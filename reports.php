<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat']);
require_once __DIR__ . '/dashboard_sidebar_helper.php';

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

$profile_picture = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
$user_id = auth_user_id();
$user_query = 'SELECT profile_picture FROM users WHERE id = ?';
$stmt = $conn->prepare($user_query);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($user_result && mysqli_num_rows($user_result) > 0) {
        $user_data = mysqli_fetch_assoc($user_result);
        if (!empty($user_data['profile_picture'])) {
            $profile_picture = 'uploads/profile_pictures/' . $user_data['profile_picture'];
        }
    }
    $stmt->close();
}

$ticketPrice = 100;

$summary = [
    'total_generated' => 0,
    'activated_count' => 0,
    'assigned_pending_count' => 0,
    'pool_count' => 0,
];
$summarySql = "SELECT
    COUNT(*) AS total_generated,
    SUM(CASE WHEN LOWER(COALESCE(t.status, 'pending')) = 'activated' THEN 1 ELSE 0 END) AS activated_count,
    SUM(CASE WHEN LOWER(COALESCE(t.status, 'pending')) = 'pending' AND ta.ticket_number IS NOT NULL THEN 1 ELSE 0 END) AS assigned_pending_count,
    SUM(CASE WHEN LOWER(COALESCE(t.status, 'pending')) = 'pending' AND ta.ticket_number IS NULL THEN 1 ELSE 0 END) AS pool_count
FROM tickets t
LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number";
$summaryResult = mysqli_query($conn, $summarySql);
if ($summaryResult && mysqli_num_rows($summaryResult) > 0) {
    $summary = array_merge($summary, mysqli_fetch_assoc($summaryResult));
}

$totalGenerated = (int) ($summary['total_generated'] ?? 0);
$activatedCount = (int) ($summary['activated_count'] ?? 0);
$assignedPendingCount = (int) ($summary['assigned_pending_count'] ?? 0);
$poolCount = (int) ($summary['pool_count'] ?? 0);
$pendingCount = $assignedPendingCount + $poolCount;

$potentialRevenue = $totalGenerated * $ticketPrice;
$realizedRevenue = $activatedCount * $ticketPrice;
$revenueAtRisk = $pendingCount * $ticketPrice;
$activationRate = $totalGenerated > 0 ? round(($activatedCount / $totalGenerated) * 100, 2) : 0;
$assignmentRate = $totalGenerated > 0 ? round((($totalGenerated - $poolCount) / $totalGenerated) * 100, 2) : 0;

$buyerStatusRows = [];
$buyerStatusSql = 'SELECT LOWER(COALESCE(status, \'pending\')) AS status_key, COUNT(*) AS buyer_count FROM buyers GROUP BY LOWER(COALESCE(status, \'pending\'))';
$buyerStatusResult = mysqli_query($conn, $buyerStatusSql);
if ($buyerStatusResult) {
    while ($row = mysqli_fetch_assoc($buyerStatusResult)) {
        $buyerStatusRows[] = $row;
    }
}

$buyerStatusMap = [
    'pending' => 0,
    'email_sent' => 0,
    'partially_activated' => 0,
    'fully_activated' => 0,
    'other' => 0,
];
foreach ($buyerStatusRows as $row) {
    $statusKey = (string) ($row['status_key'] ?? 'other');
    $count = (int) ($row['buyer_count'] ?? 0);
    if (array_key_exists($statusKey, $buyerStatusMap)) {
        $buyerStatusMap[$statusKey] = $count;
    } else {
        $buyerStatusMap['other'] += $count;
    }
}

$monthlyLabels = [];
$monthlyGenerated = [];
$monthlyActivated = [];
$monthlyRevenue = [];
$monthlySql = "SELECT
    DATE_FORMAT(created_at, '%Y-%m') AS month_key,
    DATE_FORMAT(created_at, '%b %Y') AS month_label,
    COUNT(*) AS generated_count,
    SUM(CASE WHEN LOWER(COALESCE(status, 'pending')) = 'activated' THEN 1 ELSE 0 END) AS activated_count
FROM tickets
GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
ORDER BY month_key ASC";
$monthlyResult = mysqli_query($conn, $monthlySql);
if ($monthlyResult) {
    while ($row = mysqli_fetch_assoc($monthlyResult)) {
        $generatedCount = (int) ($row['generated_count'] ?? 0);
        $activatedMonthlyCount = (int) ($row['activated_count'] ?? 0);

        $monthlyLabels[] = (string) ($row['month_label'] ?? 'Unknown');
        $monthlyGenerated[] = $generatedCount;
        $monthlyActivated[] = $activatedMonthlyCount;
        $monthlyRevenue[] = $activatedMonthlyCount * $ticketPrice;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #283555;
            --secondary: #3a4a6f;
            --accent: #ff6b35;
            --light: #f4f6f9;
            --dark: #2c3e50;
            --white: #ffffff;
            --border-color: #e8eef5;
        }

        body {
            background: linear-gradient(135deg, #f4f6f9 0%, #ffffff 100%);
            color: var(--dark);
            min-height: 100vh;
        }

        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: flex-end;
            box-shadow: 0 4px 15px rgba(40, 53, 85, 0.15);
        }

        .profile {
            position: relative;
            cursor: pointer;
        }

        .profile img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
        }

        .profile-dropdown {
            position: absolute;
            right: 0;
            top: 55px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 150px;
            overflow: hidden;
            z-index: 100;
        }

        .profile-dropdown a {
            display: block;
            padding: 12px 20px;
            color: var(--dark);
            text-decoration: none;
            font-size: 14px;
            border-bottom: 1px solid #eee;
        }

        .profile-dropdown a:hover {
            background: #f0f0f0;
        }

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 77px);
        }

        aside.sidebar {
            width: 250px;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            color: #fff;
            padding: 22px 20px 30px;
            position: fixed;
            top: 77px;
            bottom: 0;
            left: 0;
            overflow-y: auto;
            box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
        }

        aside.sidebar .sidebar-header h1 {
            font-size: 16px;
            color: #fff;
            text-transform: uppercase;
            margin: 0;
            text-align: center;
        }

        aside.sidebar nav ul {
            list-style: none;
            margin-top: 10px;
        }

        aside.sidebar nav ul li {
            margin: 10px 0;
        }

        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            color: #f4f6f9;
            text-decoration: none;
            font-size: 16px;
            padding: 8px 12px;
            border-radius: 5px;
            transition: 0.3s;
        }

        aside.sidebar nav ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background: rgba(255, 107, 53, 0.2);
            border-left: 3px solid var(--accent);
            padding-left: 15px;
        }

        main.main-content {
            flex: 1;
            margin-left: 280px;
            padding: 24px;
        }

        .page-header {
            margin-bottom: 18px;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.9rem;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #6c757d;
            font-size: 0.95rem;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }

        .kpi-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 8px 20px rgba(40, 53, 85, 0.08);
        }

        .kpi-title {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kpi-value {
            font-size: 1.7rem;
            color: var(--primary);
            font-weight: 700;
            line-height: 1.1;
        }

        .kpi-sub {
            font-size: 0.85rem;
            color: #4b5563;
            margin-top: 4px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        .chart-card,
        .table-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 8px 20px rgba(40, 53, 85, 0.08);
        }

        .chart-card h3,
        .table-card h3 {
            font-size: 1rem;
            color: var(--primary);
            margin-bottom: 12px;
        }

        .table-card table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .table-card th,
        .table-card td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #edf1f5;
        }

        .table-card th {
            color: #374151;
            font-weight: 600;
            background: #f8fafc;
        }

        @media (max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }

            aside.sidebar {
                width: 100%;
                position: relative;
                top: 0;
                box-shadow: none;
            }

            main.main-content {
                margin-left: 0;
                padding: 12px;
            }

            header {
                padding: 0.8rem 1rem;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="profile" onclick="toggleProfileDropdown(event)">
        <img src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES); ?>" alt="Profile">
        <div class="profile-dropdown" id="profileDropdown">
            <a href="update.php">Update</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/reports.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
        <div class="page-header">
            <h2>Ticket Reporting Dashboard</h2>
            <p>All-time operational snapshot with monthly trends. Ticket value is fixed at PHP <?php echo number_format($ticketPrice); ?>.</p>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-title">Total Ticket Numbers Generated</div>
                <div class="kpi-value"><?php echo number_format($totalGenerated); ?></div>
                <div class="kpi-sub">Revenue potential: PHP <?php echo number_format($potentialRevenue, 2); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Activated Ticket Numbers</div>
                <div class="kpi-value"><?php echo number_format($activatedCount); ?></div>
                <div class="kpi-sub">Activation rate: <?php echo number_format($activationRate, 2); ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Ticket Pool (Unassigned Pending)</div>
                <div class="kpi-value"><?php echo number_format($poolCount); ?></div>
                <div class="kpi-sub">Assignment coverage: <?php echo number_format($assignmentRate, 2); ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Assigned Pending Tickets</div>
                <div class="kpi-value"><?php echo number_format($assignedPendingCount); ?></div>
                <div class="kpi-sub">Awaiting activation from assigned buyers</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Realized Revenue</div>
                <div class="kpi-value">PHP <?php echo number_format($realizedRevenue, 2); ?></div>
                <div class="kpi-sub">From activated tickets only</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Revenue at Risk</div>
                <div class="kpi-value">PHP <?php echo number_format($revenueAtRisk, 2); ?></div>
                <div class="kpi-sub">Pending + pool ticket value</div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Ticket Trend (Generated vs Activated)</h3>
                <canvas id="monthlyTrendChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Ticket Distribution</h3>
                <canvas id="ticketMixChart"></canvas>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Realized Revenue</h3>
                <canvas id="monthlyRevenueChart"></canvas>
            </div>
            <div class="table-card">
                <h3>Buyer Status Demographics</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Buyers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Pending</td>
                            <td><?php echo number_format($buyerStatusMap['pending']); ?></td>
                        </tr>
                        <tr>
                            <td>Email Sent</td>
                            <td><?php echo number_format($buyerStatusMap['email_sent']); ?></td>
                        </tr>
                        <tr>
                            <td>Partially Activated</td>
                            <td><?php echo number_format($buyerStatusMap['partially_activated']); ?></td>
                        </tr>
                        <tr>
                            <td>Fully Activated</td>
                            <td><?php echo number_format($buyerStatusMap['fully_activated']); ?></td>
                        </tr>
                        <tr>
                            <td>Other</td>
                            <td><?php echo number_format($buyerStatusMap['other']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleProfileDropdown(event) {
        event.stopPropagation();
        var dropdown = document.getElementById('profileDropdown');
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
    }

    document.addEventListener('click', function () {
        var dropdown = document.getElementById('profileDropdown');
        dropdown.style.display = 'none';
    });

    var monthlyLabels = <?php echo json_encode($monthlyLabels); ?>;
    var monthlyGenerated = <?php echo json_encode($monthlyGenerated); ?>;
    var monthlyActivated = <?php echo json_encode($monthlyActivated); ?>;
    var monthlyRevenue = <?php echo json_encode($monthlyRevenue); ?>;

    var ticketMix = {
        activated: <?php echo json_encode($activatedCount); ?>,
        assignedPending: <?php echo json_encode($assignedPendingCount); ?>,
        pool: <?php echo json_encode($poolCount); ?>
    };

    new Chart(document.getElementById('monthlyTrendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: monthlyLabels,
            datasets: [
                {
                    label: 'Generated',
                    data: monthlyGenerated,
                    borderColor: '#283555',
                    backgroundColor: 'rgba(40, 53, 85, 0.12)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Activated',
                    data: monthlyActivated,
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('ticketMixChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Activated', 'Assigned Pending', 'Pool'],
            datasets: [{
                data: [ticketMix.activated, ticketMix.assignedPending, ticketMix.pool],
                backgroundColor: ['#27ae60', '#f39c12', '#e74c3c']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    new Chart(document.getElementById('monthlyRevenueChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Realized Revenue (PHP)',
                data: monthlyRevenue,
                backgroundColor: '#ff6b35'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>
</body>
</html>


