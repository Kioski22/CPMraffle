<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$usersExist = false;
$flashMessage = '';
$flashType = 'success';

try {
    $checkUsers = $conn->query("SHOW TABLES LIKE 'users'");
    if ($checkUsers instanceof mysqli_result && $checkUsers->num_rows > 0) {
        $countResult = $conn->query('SELECT COUNT(*) AS total FROM users');
        if ($countResult instanceof mysqli_result) {
            $countRow = $countResult->fetch_assoc();
            $usersExist = ((int) ($countRow['total'] ?? 0)) > 0;
        }
    }
} catch (Throwable $e) {
    $usersExist = true;
}

$isAdminMode = false;
$profile_picture = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

if ($usersExist) {
    auth_require_roles(['admin']);
    $isAdminMode = true;

    $userId = auth_user_id();
    $userQuery = $conn->prepare('SELECT profile_picture FROM users WHERE id = ?');
    if ($userQuery) {
        $userQuery->bind_param('i', $userId);
        $userQuery->execute();
        $userResult = $userQuery->get_result();
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            if (!empty($userData['profile_picture'])) {
                $profile_picture = 'uploads/profile_pictures/' . $userData['profile_picture'];
            }
        }
        $userQuery->close();
    }
}

$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

if (isset($_POST['submit'])) {
    try {
        $checkTableResult = $conn->query("SHOW TABLES LIKE 'users'");

        if ($checkTableResult instanceof mysqli_result && $checkTableResult->num_rows === 0) {
            $conn->query("CREATE TABLE users (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin'
            )");
        }

        $roleColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($roleColumnResult instanceof mysqli_result && $roleColumnResult->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'");
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');

        if ($name === '' || $user === '' || $pass === '') {
            throw new RuntimeException('Name, username, and password are required.');
        }

        $hashedPassword = password_hash($pass, PASSWORD_DEFAULT);
        $defaultRole = 'admin';

        if ($usersExist) {
            $requestedRole = auth_normalize_role((string) ($_POST['role'] ?? ''));
            if ($requestedRole === '') {
                throw new RuntimeException('Invalid role selected.');
            }
            $defaultRole = $requestedRole;
        }

        $stmt = $conn->prepare('INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare registration query.');
        }

        $stmt->bind_param('ssss', $name, $user, $hashedPassword, $defaultRole);
        $stmt->execute();
        $stmt->close();

        $flashMessage = 'User registered successfully.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        $flashMessage = 'Registration failed: ' . $exception->getMessage();
        $flashType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
            --danger: #e74c3c;
            --success: #27ae60;
        }

        body {
            background: linear-gradient(rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.86)),
            url(https://scontent.fmnl4-4.fna.fbcdn.net/v/t39.30808-6/476909789_122193973430148434_6008072516477437592_n.jpg?_nc_cat=100&ccb=1-7&_nc_sid=127cfc&_nc_eui2=AeFsvBVC1ThndkRAwcH7zQGwEktyJHj32KcSS3IkePfYpw5lHdjfKKRPrEJjLJAqDtVF7HMg9JUOL2VH7SZRM3xB&_nc_ohc=GgqG9UeHWTQQ7kNvwEFCQQ0&_nc_oc=Adm3qKArdu10juPhyCuPAKNIp5c3y4NTN1Wyr__c_6kMUVRkyt0MTeTvBp80EkCUDzM&_nc_zt=23&_nc_ht=scontent.fmnl4-4.fna&_nc_gid=I56dKqWKzSQOBN8ZFHKCuw&oh=00_AfFij6rjTWEc1GpBRBUXyZeCTrOaSO8U8cV67xAxAgLyqw&oe=67F90206);
            background-size: cover;
            background-position: center;
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
            display: inline-block;
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
            background: var(--white);
            border: 1px solid #ddd;
            border-radius: 8px;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 150px;
            z-index: 100;
            overflow: hidden;
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
            color: var(--light);
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
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .form-card {
            width: 100%;
            max-width: 520px;
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(40, 53, 85, 0.12);
            padding: 26px;
        }

        .form-card h2 {
            color: var(--primary);
            margin-bottom: 8px;
        }

        .form-card p {
            color: #6b7280;
            margin-bottom: 18px;
            font-size: 0.93rem;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            color: #374151;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            color: #111827;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.12);
        }

        .btn-submit {
            width: 100%;
            padding: 11px 12px;
            border: none;
            border-radius: 8px;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .alert {
            border-radius: 8px;
            padding: 11px 12px;
            margin-bottom: 14px;
            font-size: 0.92rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: #ecfdf3;
            border-color: #bbf7d0;
            color: #166534;
        }

        .alert-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .setup-shell {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .setup-note {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 10px;
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
        }
    </style>
</head>
<body>
<?php if ($isAdminMode): ?>
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
    <?php render_dashboard_sidebar('/CPMraffle/reg.php', true, $dashboardPath); ?>

    <main class="main-content">
        <div class="form-card">
            <h2>Register User</h2>
            <p>Create a new admin or secretariat account.</p>
            <?php if ($flashMessage !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>">
                    <?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
                </div>
            <?php endif; ?>
            <form action="" method="post">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="secretariat">Secretariat</option>
                        <option value="cpmadmin">CPM Admin</option>
                    </select>
                </div>
                <button class="btn-submit" type="submit" name="submit">Register</button>
            </form>
        </div>
    </main>
</div>
<?php else: ?>
<div class="setup-shell">
    <div class="form-card">
        <h2>Initial Admin Setup</h2>
        <p>Create the first admin account to initialize the system.</p>
        <?php if ($flashMessage !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>">
                <?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
            </div>
        <?php endif; ?>
        <form action="" method="post">
            <div class="form-group">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" required>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input id="username" type="text" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
            </div>
            <button class="btn-submit" type="submit" name="submit">Create Admin</button>
        </form>
        <div class="setup-note">Role defaults to admin during first-time setup.</div>
    </div>
</div>
<?php endif; ?>

<script>
    function toggleProfileDropdown(event) {
        event.stopPropagation();
        var dropdown = document.getElementById('profileDropdown');
        if (!dropdown) {
            return;
        }
        var currentDisplay = window.getComputedStyle(dropdown).display;
        dropdown.style.display = currentDisplay === 'none' ? 'block' : 'none';
    }

    document.addEventListener('click', function () {
        var dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
    });
</script>
</body>
</html>


