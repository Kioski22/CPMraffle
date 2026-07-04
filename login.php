<?php
include 'db.php';
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$loginError = '';
$loginPortal = strtolower(trim((string) ($_GET['portal'] ?? '')));
$isChapterPortal = $loginPortal === 'chapter';
$loginPageTitle = $isChapterPortal ? 'Chapter Login - Ticket Management System' : 'Admin Login - Ticket Management System';
$loginHeaderTitle = $isChapterPortal ? 'Chapter Login' : 'Admin Login';
$loginHeaderSubtitle = $isChapterPortal
    ? 'Chapter presidents sign in here to manage your allocated tickets.'
    : 'Authorized admins and secretariat staff can sign in here.';
$loginFooterText = $isChapterPortal
    ? 'Chapter presidents portal'
    : 'Secure login for authorized admins and secretariat only';
$alternatePortalLink = $isChapterPortal ? 'login.php' : 'chapter_login.php';
$alternatePortalLabel = $isChapterPortal ? 'Back to Admin Login' : 'Chapter Presidents Login';

if (!isset($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

function usersTableHasRoleColumn(mysqli $conn): bool {
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function login_prune_attempts(): array {
    $windowStart = time() - 900;
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts = array_values(array_filter($attempts, static function ($timestamp) use ($windowStart) {
        return is_int($timestamp) && $timestamp >= $windowStart;
    }));
    $_SESSION['login_attempts'] = $attempts;

    return $attempts;
}

function login_record_attempt(): void {
    $attempts = login_prune_attempts();
    $attempts[] = time();
    $_SESSION['login_attempts'] = $attempts;
}

if (auth_is_logged_in()) {
    auth_redirect_to_dashboard();
}


if (isset($_POST['login'])) {
    if (!hash_equals((string) $_SESSION['login_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $loginError = 'Invalid request. Please refresh and try again.';
    }

    $attempts = login_prune_attempts();
    if ($loginError === '' && count($attempts) >= 5) {
        $loginError = 'Too many login attempts. Please try again in 15 minutes.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($loginError === '' && ($username === '' || strlen($username) > 100 || $password === '' || strlen($password) > 255)) {
        $loginError = 'Username or password is incorrect.';
    }

    try {
        if ($loginError === '') {
            $selectFields = usersTableHasRoleColumn($conn)
                ? "id, username, password, role"
                : "id, username, password";

            $stmt = $conn->prepare("SELECT {$selectFields} FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row && password_verify($password, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['id'] = $row['id'];
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = auth_normalize_role((string) ($row['role'] ?? '')) ?: 'admin';
                $_SESSION['login_attempts'] = [];

                auth_redirect_to_dashboard();
            }
        }
    } catch (mysqli_sql_exception $exception) {
        $loginError = 'Unable to process login right now. Please try again.';
    }

    if ($loginError === '') {
        login_record_attempt();
        $loginError = 'Username or password is incorrect.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($loginPageTitle, ENT_QUOTES); ?></title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #283555 0%, #1a2438 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
        }

        .login-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideInUp 0.5s ease-out;
        }

        .login-header {
            background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%);
            padding: 40px 30px 30px;
            text-align: center;
            color: white;
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #283555;
            box-shadow: 0 0 0 4px rgba(40, 53, 85, 0.1);
        }

        .form-group input::placeholder {
            color: #9ca3af;
        }

        .error-message {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInDown 0.3s ease-out;
        }

        .error-message i {
            flex-shrink: 0;
        }

        .login-button {
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(40, 53, 85, 0.3);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .login-footer {
            background: #f9fafb;
            padding: 20px 30px;
            text-align: center;
            font-size: 13px;
            color: #6b7280;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .login-header {
                padding: 30px 20px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .login-body {
                padding: 30px 20px;
            }

            .login-footer {
                padding: 15px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><?php echo htmlspecialchars($loginHeaderTitle, ENT_QUOTES); ?></h1>
                <p><?php echo htmlspecialchars($loginHeaderSubtitle, ENT_QUOTES); ?></p>
            </div>
            <div class="login-body">
                <form action="" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['login_csrf'], ENT_QUOTES); ?>">
                    <?php if ($loginError !== ''): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($loginError); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                    <button type="submit" name="login" class="login-button">
                        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i>Login
                    </button>
                </form>
            </div>
            <div class="login-footer">
                <i class="fas fa-lock" style="margin-right: 5px;"></i>
                <?php echo htmlspecialchars($loginFooterText, ENT_QUOTES); ?>
                <div style="margin-top: 8px;">
                    <a href="<?php echo htmlspecialchars($alternatePortalLink, ENT_QUOTES); ?>" style="color: #283555; font-weight: 600; text-decoration: none;">
                        <?php echo htmlspecialchars($alternatePortalLabel, ENT_QUOTES); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
