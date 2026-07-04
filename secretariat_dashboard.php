<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['secretariat']);
include 'db.php';

$profile_picture = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
$userId = auth_user_id();
$userName = 'Secretariat';
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
    <title>Secretariat Dashboard</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_cards.css">
</head>
<body>
    <div class="topbar">
        <h1>6th PSME CPM National Conference Dashboard</h1>
        <div class="user-meta">
            <span class="name"><?php echo htmlspecialchars($userName, ENT_QUOTES); ?></span>
            <span class="role-pill">SECRETARIAT</span>
            <img src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES); ?>" alt="Profile">
        </div>
    </div>

    <div class="wrapper">
        <div class="intro">
            <h2>Welcome</h2>
            <p>You can encode buyers, assign tickets, and view buyer and ticket status pages. Buyer updates and admin settings are restricted.</p>
        </div>

        <div class="grid">
            <a class="card" href="/CPMraffle/buyer.php">
                <i class="fas fa-user-plus"></i>
                <h3>Buyer Assignment</h3>
                <p>Encode buyer details and assign available tickets.</p>
            </a>
            <a class="card" href="/CPMraffle/admin_purchase_requests.php">
                <i class="fas fa-cash-register"></i>
                <h3>Onsite Requests</h3>
                <p>Review payment proofs and approve onsite ticket purchase requests.</p>
            </a>
            <a class="card" href="/CPMraffle/bulk_buyer_upload_secretariat.php">
                <i class="fas fa-file-upload"></i>
                <h3>Bulk Buyer Upload</h3>
                <p>Upload CSV files to assign chapter buyers in batch.</p>
            </a>
            <a class="card" href="/CPMraffle/admin_chapter_allocation.php">
                <i class="fas fa-building"></i>
                <h3>Chapter Allocation Setup</h3>
                <p>Create chapter account and assign ticket ranges in one flow.</p>
            </a>
            <a class="card" href="/CPMraffle/admin_chapter_list.php">
                <i class="fas fa-table-list"></i>
                <h3>Chapter Monitoring</h3>
                <p>Track chapter pools, owner assignments, and ticket status.</p>
            </a>
            <a class="card" href="/CPMraffle/chapter_management.php">
                <i class="fas fa-user-gear"></i>
                <h3>Legacy Chapter Account</h3>
                <p>Standalone chapter account creation page.</p>
            </a>
            <a class="card" href="/CPMraffle/assign_tickets_to_chapter.php">
                <i class="fas fa-share-square"></i>
                <h3>Legacy Chapter Allocation</h3>
                <p>Standalone ticket range allocation page.</p>
            </a>
            <a class="card" href="/CPMraffle/participant_list.php">
                <i class="fas fa-users"></i>
                <h3>Buyer List</h3>
                <p>View buyer records and activation progress.</p>
            </a>
            <a class="card" href="/CPMraffle/pending_tickets.php">
                <i class="fas fa-hourglass-half"></i>
                <h3>Pending Tickets</h3>
                <p>Review unactivated ticket totals and trends.</p>
            </a>
            <a class="card" href="/CPMraffle/activated_tickets.php">
                <i class="fas fa-check-circle"></i>
                <h3>Activated Tickets</h3>
                <p>Review activated ticket totals and trends.</p>
            </a>
            <a class="card" href="/CPMraffle/pool_tickets.php">
                <i class="fas fa-layer-group"></i>
                <h3>Ticket Pool</h3>
                <p>View available ticket pool status.</p>
            </a>
            <a class="card" href="/CPMraffle/reports.php">
                <i class="fas fa-chart-line"></i>
                <h3>Reports</h3>
                <p>Review assigned, activated, and pool ticket dashboards.</p>
            </a>
        </div>

        <div class="actions">
            <a class="btn secondary" href="/CPMraffle/update.php"><i class="fas fa-user-edit"></i> Update Profile</a>
            <a class="btn" href="/CPMraffle/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</body>
</html>
