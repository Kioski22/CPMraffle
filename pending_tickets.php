<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat']);
require_once __DIR__ . '/dashboard_sidebar_helper.php';
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

// Fetch user profile picture using a prepared statement
$profile_picture = "https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png"; // Default image if no profile picture found
$user_id = $_SESSION['id'];
$user_query = "SELECT profile_picture FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
if ($user_result && mysqli_num_rows($user_result) > 0) {
    $user_data = mysqli_fetch_assoc($user_result);
    if (!empty($user_data['profile_picture'])) {
        $profile_picture = "uploads/profile_pictures/" . $user_data['profile_picture']; // Path to the uploaded image
    }
}

// Query to get the total number of pending tickets
$queryTotal = "SELECT COUNT(*) as total FROM tickets WHERE LOWER(status) = 'pending'";
$resultTotal = mysqli_query($conn, $queryTotal);
$totalPending = 0;
if ($resultTotal && mysqli_num_rows($resultTotal) > 0) {
    $row = mysqli_fetch_assoc($resultTotal);
    $totalPending = $row['total'];
}

$allowedPerPage = [25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalPending / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;

$pendingTickets = [];
$pendingListStmt = $conn->prepare("SELECT t.ticket_number, t.created_at,
        COALESCE(u.chapter_name, u.name, '') AS chapter_name
    FROM tickets t
    LEFT JOIN users u ON u.id = t.assigned_chapter_id
    WHERE LOWER(t.status) = 'pending'
    ORDER BY t.ticket_number ASC
    LIMIT ? OFFSET ?");

if ($pendingListStmt) {
    $pendingListStmt->bind_param('ii', $perPage, $offset);
    $pendingListStmt->execute();
    $pendingResult = $pendingListStmt->get_result();
    while ($pendingResult && ($ticketRow = $pendingResult->fetch_assoc())) {
        $pendingTickets[] = $ticketRow;
    }
    $pendingListStmt->close();
}

$startRecord = $totalPending > 0 ? ($offset + 1) : 0;
$endRecord = $totalPending > 0 ? min($offset + count($pendingTickets), $totalPending) : 0;

$paginationBaseParams = ['per_page' => $perPage];

$pageLinks = [];
if ($totalPages <= 7) {
    for ($p = 1; $p <= $totalPages; $p++) {
        $pageLinks[] = $p;
    }
} else {
    $startWindow = max(1, $currentPage - 2);
    $endWindow = min($totalPages, $currentPage + 2);

    if ($startWindow > 1) {
        $pageLinks[] = 1;
        if ($startWindow > 2) {
            $pageLinks[] = -1;
        }
    }

    for ($p = $startWindow; $p <= $endWindow; $p++) {
        $pageLinks[] = $p;
    }

    if ($endWindow < $totalPages) {
        if ($endWindow < $totalPages - 1) {
            $pageLinks[] = -1;
        }
        $pageLinks[] = $totalPages;
    }
}

// Query to group pending tickets by month (January till October)
$ticketCounts = [];
$query = "SELECT DATE_FORMAT(created_at, '%M') as month, COUNT(*) as count 
          FROM tickets 
          WHERE LOWER(status) = 'pending' AND MONTH(created_at) <= 10 
          GROUP BY MONTH(created_at) 
          ORDER BY MONTH(created_at)";
$result = mysqli_query($conn, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $ticketCounts[] = $row;
    }
}
$chartLabels = array_column($ticketCounts, 'month');
$chartData = array_column($ticketCounts, 'count');
?>
<!DOCTYPE html>
<html lang="en">

<head>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - Not Activated Tickets</title>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome for icons -->
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <style>
        /* Global Reset and CSS Variables */
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
            --gray: #6c757d;
            --bg: #f4f6f9;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --border-color: #e8eef5;
            --transition-speed: 0.3s;
        }

        html,
        body {
            background: linear-gradient(rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0.85)),
        url(https://scontent.fmnl4-4.fna.fbcdn.net/v/t39.30808-6/476909789_122193973430148434_6008072516477437592_n.jpg?_nc_cat=100&ccb=1-7&_nc_sid=127cfc&_nc_eui2=AeFsvBVC1ThndkRAwcH7zQGwEktyJHj32KcSS3IkePfYpw5lHdjfKKRPrEJjLJAqDtVF7HMg9JUOL2VH7SZRM3xB&_nc_ohc=GgqG9UeHWTQQ7kNvwEFCQQ0&_nc_oc=Adm3qKArdu10juPhyCuPAKNIp5c3y4NTN1Wyr__c_6kMUVRkyt0MTeTvBp80EkCUDzM&_nc_zt=23&_nc_ht=scontent.fmnl4-4.fna&_nc_gid=I56dKqWKzSQOBN8ZFHKCuw&oh=00_AfFij6rjTWEc1GpBRBUXyZeCTrOaSO8U8cV67xAxAgLyqw&oe=67F90206);
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            color: var(--dark);

        }

        /* Header & Profile */
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            box-shadow: 0 4px 15px rgba(40, 53, 85, 0.15);
            position: relative;
            z-index: 1;
        }
            align-items: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 1;
        }

        .profile {
  position: relative;
  cursor: pointer;
  display: inline-block;
}

/* Profile Image Styling */
.profile img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  transition: transform var(--transition-speed) ease, box-shadow 0.3s ease;
  object-fit: cover;
  border: 2px solid white; /* Add white border around the image */
}

.profile img:hover {
  transform: scale(1.1);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Profile Dropdown Menu */
.profile-dropdown {
  position: absolute;
  right: 0;
  top: 55px;
  background: var(--white);
  border: 1px solid #ddd;
  border-radius: 8px;
  display: none;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  z-index: 100;
  min-width: 150px;
  overflow: hidden;
}

/* Profile Dropdown Links */
.profile-dropdown a {
  display: block;
  padding: 12px 20px;
  color: var(--dark);
  text-decoration: none;
  font-size: 14px;
  transition: background var(--transition-speed) ease, padding-left 0.3s;
  border-bottom: 1px solid #eee;
}

.profile-dropdown a:hover {
  background: #f0f0f0;
  padding-left: 25px;
}

/* Profile Dropdown Visibility */
.profile-dropdown.show {
  display: block;
}

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        /* Sidebar - keep background image intact */
        aside.sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            color: var(--light);
            padding: 22px 20px 30px 20px;
            position: fixed;
            z-index: 2;
            bottom: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
        }

        aside.sidebar .sidebar-header {
            text-align: center;
            margin-bottom: 10px;
        }

        aside.sidebar .sidebar-header h1 {
            font-size: 16px;
            color: var(--white);
            text-transform: uppercase;
            margin: 0;
        }

        aside.sidebar nav ul {
            list-style: none;
            padding: 0;
            margin-top: 10px;
        }

        aside.sidebar nav ul li {
            margin: 10px 0;
        }

        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            color: var(--light);
            text-decoration: none;
            font-size: 16px;
            transition: background var(--transition-speed) ease, padding-left var(--transition-speed) ease;
            padding: 8px 12px;
            border-radius: 5px;
        }

        aside.sidebar nav ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        aside.sidebar nav ul li a:hover {
            background: rgba(255, 107, 53, 0.2);
            padding-left: 15px;
            border-left: 3px solid var(--accent);
        }

        /* Main Content Area */
        .main-content {
            margin-left: 280px;
            padding: 2rem;
            width: calc(100% - 280px);
        }

        /* Ticket Card */
        .cards {
            display: flex;
            justify-content: center;
            /* Center the card */
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            width: 300px;
            border: 1px solid var(--border-color);
            /* Set a fixed width for the card */
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(40, 53, 85, 0.15);
        }

        .card h3 {
            margin-bottom: 0.5rem;
            font-size: 2rem;
            /* Increased font size */
            color: var(--primary);
            text-align: center;
            /* Center align the text */
        }

        .card p {
            font-size: 1.5rem;
            /* Increased font size */
            color: var(--dark);
            text-align: center;
            /* Center align the text */
        }

        .center_total {
            text-align: center;
            font-size: large;
            /* Adjust this value as needed */
            font-weight: bold;
            margin: 0.5rem 0;
        }

        /* Chart Container */
        .chart-container {
            background: var(--white);
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .table-card {
            background: var(--white);
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin-top: 1.5rem;
        }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .table-controls select {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.35rem 0.5rem;
            background: #fff;
            color: var(--dark);
        }

        .pending-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pending-table th,
        .pending-table td {
            border-bottom: 1px solid var(--border-color);
            padding: 0.7rem 0.6rem;
            text-align: left;
            font-size: 0.92rem;
        }

        .pending-table th {
            color: var(--primary);
            font-weight: 700;
            background: #f7f9fc;
        }

        .pending-table tbody tr:hover {
            background: #fafcff;
        }

        .pagination {
            list-style: none;
            display: flex;
            gap: 0.35rem;
            justify-content: center;
            margin: 1rem 0 0;
            padding: 0;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            min-width: 34px;
            text-align: center;
            padding: 0.4rem 0.55rem;
            border: 1px solid var(--border-color);
            border-radius: 7px;
            color: var(--primary);
            text-decoration: none;
            background: #fff;
            font-size: 0.9rem;
        }

        .pagination .active span {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .pagination .disabled span {
            color: #a3afbf;
            background: #f7f9fc;
        }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }

            aside.sidebar {
                width: 100%;
                text-align: center;
                padding: 15px;
                position: relative;
                box-shadow: none;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <header>
        <!-- Profile Icon & Dropdown -->
        <div class="profile" id="profileIcon">
            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile">
            <div class="profile-dropdown" id="profileDropdown">
                <a href="update.php">Update</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </header>
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <?php render_dashboard_sidebar('/CPMraffle/pending_tickets.php', $isAdmin, $dashboardPath); ?>
        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Ticket Cards Section -->
            <div class="cards">
                <div class="card">
                    <h3 style="color: rgba(6,6,93,255);">Total Not Activated Tickets</h3>
                    <p><?php echo number_format($totalPending); ?></p>
                </div>
            </div>

            <h1> Not Activated Tickets</h1>

            <!-- Chart Section -->
            <div class="chart-container">
                <canvas id="ticketChart"></canvas>
            </div>

            <div class="table-card">
                <div class="table-controls">
                    <div>
                        <strong>Pending Tickets List</strong><br>
                        <small>Showing <?php echo (int) $startRecord; ?>-<?php echo (int) $endRecord; ?> of <?php echo (int) $totalPending; ?> pending tickets</small>
                    </div>
                    <form method="GET">
                        <label for="per_page">Rows per page:</label>
                        <select id="per_page" name="per_page" onchange="this.form.submit()">
                            <?php foreach ($allowedPerPage as $size): ?>
                                <option value="<?php echo (int) $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo (int) $size; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="page" value="1">
                    </form>
                </div>

                <div style="overflow-x:auto;">
                    <table class="pending-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ticket Number</th>
                                <th>Chapter</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pendingTickets === []): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;color:#6b7280;padding:1rem;">No pending tickets found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pendingTickets as $index => $ticketRow): ?>
                                    <tr>
                                        <td><?php echo (int) ($offset + $index + 1); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($ticketRow['ticket_number'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string) (($ticketRow['chapter_name'] ?? '') !== '' ? $ticketRow['chapter_name'] : 'Unassigned'), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($ticketRow['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <ul class="pagination" aria-label="Pending tickets pagination">
                        <?php
                        $prevParams = $paginationBaseParams;
                        $prevParams['page'] = max(1, $currentPage - 1);
                        ?>
                        <li class="<?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                            <?php if ($currentPage <= 1): ?>
                                <span>Prev</span>
                            <?php else: ?>
                                <a href="?<?php echo htmlspecialchars(http_build_query($prevParams), ENT_QUOTES); ?>">Prev</a>
                            <?php endif; ?>
                        </li>

                        <?php foreach ($pageLinks as $pageNumber): ?>
                            <?php if ($pageNumber === -1): ?>
                                <li class="disabled"><span>...</span></li>
                            <?php else: ?>
                                <?php
                                $pageParams = $paginationBaseParams;
                                $pageParams['page'] = $pageNumber;
                                ?>
                                <li class="<?php echo $pageNumber === $currentPage ? 'active' : ''; ?>">
                                    <?php if ($pageNumber === $currentPage): ?>
                                        <span><?php echo (int) $pageNumber; ?></span>
                                    <?php else: ?>
                                        <a href="?<?php echo htmlspecialchars(http_build_query($pageParams), ENT_QUOTES); ?>"><?php echo (int) $pageNumber; ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php
                        $nextParams = $paginationBaseParams;
                        $nextParams['page'] = min($totalPages, $currentPage + 1);
                        ?>
                        <li class="<?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                            <?php if ($currentPage >= $totalPages): ?>
                                <span>Next</span>
                            <?php else: ?>
                                <a href="?<?php echo htmlspecialchars(http_build_query($nextParams), ENT_QUOTES); ?>">Next</a>
                            <?php endif; ?>
                        </li>
                    </ul>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        document.getElementById('profileIcon').addEventListener('click', function(event) {
            event.stopPropagation();
            document.getElementById('profileDropdown').classList.toggle('show');
        });
        window.addEventListener('click', function(event) {
            if (!document.getElementById('profileIcon').contains(event.target)) {
                document.getElementById('profileDropdown').classList.remove('show');
            }
        });


        // Prepare chart data from PHP variables
        var chartLabels = <?php echo json_encode($chartLabels); ?>;
        var chartData = <?php echo json_encode($chartData); ?>;
        // Create a line chart using Chart.js if data is available
        if (chartLabels.length > 0 && chartData.length > 0) {
            var ctx = document.getElementById('ticketChart').getContext('2d');
            var ticketChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Pending Tickets by Month',
                        data: chartData,
                        fill: false,
                        borderColor: 'rgba(231, 76, 60, 1)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
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
        }
    </script>
</body>

</html>

