<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';
auth_require_roles(['admin']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/admin_dashboard.php';

$profile_picture = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
$userId = auth_user_id();
$userName = 'Admin';

$stmt = $conn->prepare('SELECT profile_picture, name, username FROM users WHERE id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (is_array($user)) {
        if (!empty($user['profile_picture'])) {
            $profile_picture = 'uploads/profile_pictures/' . $user['profile_picture'];
        }

        $nameCandidate = trim((string) ($user['name'] ?? ''));
        if ($nameCandidate !== '') {
            $userName = $nameCandidate;
        } else {
            $usernameCandidate = trim((string) ($user['username'] ?? ''));
            if ($usernameCandidate !== '') {
                $userName = $usernameCandidate;
        }
    }
}

// Fetch last assigned ticket
$lastTicketQuery = "
    SELECT ta.ticket_number, ta.assigned_at, b.buyer_type, b.full_name, b.chapter
    FROM ticket_assignments ta
    JOIN buyers b ON ta.buyer_id = b.id
    ORDER BY ta.assigned_at DESC, ta.id DESC
    LIMIT 1
";
$lastTicketResult = $conn->query($lastTicketQuery);
$lastAssignedTicket = null;
if ($lastTicketResult && $lastTicketResult->num_rows > 0) {
    $lastAssignedTicket = $lastTicketResult->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard_cards.css">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <style>
        .dashboard-container { display: flex; min-height: 100vh; }
        .main-content { margin-left: 280px; flex: 1; min-width: 0; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php render_dashboard_sidebar('/CPMraffle/admin_dashboard.php', $isAdmin, $dashboardPath); ?>
        <div class="main-content">
    <div class="topbar">
        <h1>6th PSME CPM National Conference Dashboard</h1>
        <div class="user-meta">
            <span class="name"><?php echo htmlspecialchars($userName, ENT_QUOTES); ?></span>
            <span class="role-pill">ADMIN</span>
            <img src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES); ?>" alt="Profile">
        </div>
    </div>

    <div class="wrapper">
        <div class="intro">
            <h2>Welcome</h2>
            <p>Manage ticket generation, buyer assignment, activation reports, templates, and user roles from this control center.</p>

            <?php if ($lastAssignedTicket): ?>
            <div class="last-assignment-banner" style="background: #e8f4f8; padding: 15px; border-left: 4px solid #3498db; margin-top: 15px; border-radius: 4px;">
                <strong>Last Ticket Assigned:</strong> 
                <?php echo htmlspecialchars($lastAssignedTicket['ticket_number']); ?>
                <span style="margin: 0 10px;">|</span>
                <strong>Assignment Type:</strong> 
                <?php 
                if ($lastAssignedTicket['buyer_type'] === 'chapter') {
                    echo 'Chapter Assignment (' . htmlspecialchars($lastAssignedTicket['chapter']) . ')';
                } else {
                    echo 'Individual Buyer (' . htmlspecialchars($lastAssignedTicket['full_name']) . ')';
                }
                ?>
                <span style="margin: 0 10px;">|</span>
                <small><i class="fas fa-clock"></i> <?php echo date('M j, Y g:i A', strtotime($lastAssignedTicket['assigned_at'])); ?></small>
            </div>
            <?php else: ?>
            <div class="last-assignment-banner" style="background: #fdf2e9; padding: 15px; border-left: 4px solid #e67e22; margin-top: 15px; border-radius: 4px;">
                <em>No tickets have been assigned yet.</em>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid">
            <a class="card" href="generate_qr.php">
                <i class="fas fa-ticket-alt"></i>
                <h3>Ticket Generation</h3>
                <p>Create and manage raffle ticket numbers.</p>
            </a>
            <a class="card" href="buyer.php">
                <i class="fas fa-user-plus"></i>
                <h3>Buyer Assignment</h3>
                <p>Encode buyer details and assign ticket ranges.</p>
            </a>
            <a class="card" href="admin_purchase_requests.php">
                <i class="fas fa-cash-register"></i>
                <h3>Onsite Requests</h3>
                <p>Review proof of payment, approve requests, and assign ticket ranges.</p>
            </a>
            <a class="card" href="bulk_buyer_upload_admin.php">
                <i class="fas fa-file-upload"></i>
                <h3>Bulk Buyer Upload</h3>
                <p>Upload CSV files for large batch assignments.</p>
            </a>
            <a class="card" href="participant_list.php">
                <i class="fas fa-users"></i>
                <h3>Buyer List</h3>
                <p>Review buyers, activation progress, and resend emails.</p>
            </a>
            <a class="card" href="activated_tickets.php">
                <i class="fas fa-check-circle"></i>
                <h3>Activated Tickets</h3>
                <p>Track activated ticket counts and trends.</p>
            </a>
            <a class="card" href="pending_tickets.php">
                <i class="fas fa-hourglass-half"></i>
                <h3>Pending Tickets</h3>
                <p>Monitor pending ticket numbers and status.</p>
            </a>
            <a class="card" href="pool_tickets.php">
                <i class="fas fa-layer-group"></i>
                <h3>Ticket Pool</h3>
                <p>View unassigned ticket pool availability.</p>
            </a>
            <a class="card" href="ticket_layout_editor.php">
                <i class="fas fa-object-group"></i>
                <h3>Ticket Layout</h3>
                <p>Edit and publish ticket visual templates.</p>
            </a>
            <a class="card" href="email_template_settings.php">
                <i class="fas fa-envelope-open-text"></i>
                <h3>Email Templates</h3>
                <p>Customize buyer email template content.</p>
            </a>
            <a class="card" href="reg.php">
                <i class="fas fa-user-shield"></i>
                <h3>Register User</h3>
                <p>Create admin and secretariat user accounts.</p>
            </a>
            <a class="card" href="admin_chapter_allocation.php">
                <i class="fas fa-building"></i>
                <h3>Chapter Allocation Setup</h3>
                <p>Create chapter account and assign ticket ranges in one flow.</p>
            </a>
            <a class="card" href="admin_chapter_list.php">
                <i class="fas fa-table-list"></i>
                <h3>Chapter Monitoring</h3>
                <p>Track chapter pools, owner assignments, and ticket status.</p>
            </a>
            <a class="card" href="chapter_management.php">
                <i class="fas fa-user-gear"></i>
                <h3>Legacy Chapter Account</h3>
                <p>Standalone chapter account creation page.</p>
            </a>
            <a class="card" href="assign_tickets_to_chapter.php">
                <i class="fas fa-share-square"></i>
                <h3>Legacy Chapter Allocation</h3>
                <p>Standalone ticket range allocation page.</p>
            </a>
            <a class="card" href="reports.php">
                <i class="fas fa-chart-line"></i>
                <h3>Reports</h3>
                <p>See performance KPIs and revenue dashboards.</p>
            </a>
        </div>

        <div class="actions">
            <a class="btn secondary" href="update.php"><i class="fas fa-user-edit"></i> Update Profile</a>
            <a class="btn" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        </div><!-- /.main-content -->
    </div><!-- /.dashboard-container -->
</body>
</html>
