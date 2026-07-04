<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
auth_require_roles(['cpmadmin']);

$profile_picture = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
$userId = auth_user_id();
$userName = 'CPM Admin';

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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPM Admin Dashboard</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard_cards.css">
</head>
<body>
    <div class="topbar">
        <h1>6th PSME CPM National Conference Dashboard</h1>
        <div class="user-meta">
            <span class="name"><?php echo htmlspecialchars($userName, ENT_QUOTES); ?></span>
            <span class="role-pill">CPM ADMIN</span>
            <img src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES); ?>" alt="Profile">
        </div>
    </div>

    <div class="wrapper">
        <div class="intro">
            <h2>Welcome</h2>
            <p>Monitor ticket activation, pending requests, onsite purchases, and participant details from this dashboard.</p>
        </div>

        <div class="grid">
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
            <a class="card" href="reports.php">
                <i class="fas fa-chart-line"></i>
                <h3>Reports</h3>
                <p>View detailed reports and analytics.</p>
            </a>
        </div>
    </div>
    <?php require_once __DIR__ . '/dashboard_sidebar_helper.php'; render_dashboard_sidebar('/CPMraffle/cpmadmin_dashboard.php', false, '/CPMraffle/cpmadmin_dashboard.php'); ?>
</body>
</html>
