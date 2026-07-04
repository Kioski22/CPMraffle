<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);
include 'db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$messages = [];
$lockFile = __DIR__ . '/uploads/.users_role_migration_done.lock';
$forceRun = isset($_GET['force']) && $_GET['force'] === '1';

if (is_file($lockFile) && !$forceRun) {
    http_response_code(403);
    echo '<h2>Role Migration Locked</h2>';
    echo '<p>This migration has already been applied and is locked.</p>';
    echo '<p>If you need to rerun it as admin, open: <code>/CPMraffle/migrate_users_role.php?force=1</code></p>';
    exit();
}

try {
    $checkUsers = $conn->query("SHOW TABLES LIKE 'users'");
    if (!$checkUsers instanceof mysqli_result || $checkUsers->num_rows === 0) {
        throw new RuntimeException('Users table does not exist. Run registration setup first.');
    }

    $checkRole = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if (!$checkRole instanceof mysqli_result || $checkRole->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'");
        $messages[] = 'Added role column to users table.';
    } else {
        $messages[] = 'Role column already exists.';
    }

    $normalizeStmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE LOWER(TRIM(COALESCE(role, ''))) NOT IN ('admin', 'secretariat')");
    if ($normalizeStmt) {
        $normalizeStmt->execute();
        $normalized = $normalizeStmt->affected_rows;
        $normalizeStmt->close();
        $messages[] = 'Normalized invalid roles to admin: ' . max(0, (int) $normalized) . ' user(s).';
    }

    $summaryResult = $conn->query("SELECT LOWER(TRIM(COALESCE(role, ''))) AS role_key, COUNT(*) AS total FROM users GROUP BY LOWER(TRIM(COALESCE(role, ''))) ORDER BY role_key");
    $roleCounts = [];
    if ($summaryResult instanceof mysqli_result) {
        while ($row = $summaryResult->fetch_assoc()) {
            $roleKey = (string) ($row['role_key'] ?? '');
            if ($roleKey === '') {
                $roleKey = 'admin';
            }
            $roleCounts[$roleKey] = (int) ($row['total'] ?? 0);
        }
    }

    $lockWritten = @file_put_contents($lockFile, 'completed_at=' . date(DATE_ATOM) . PHP_EOL);
    if ($lockWritten === false) {
        $messages[] = 'Warning: migration completed but lock file could not be written.';
    } else {
        $messages[] = 'Migration lock enabled.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Role Migration Failed</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Role Migration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            color: #1f2937;
            margin: 0;
            padding: 30px;
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        }
        h1 {
            margin-top: 0;
            color: #283555;
        }
        ul {
            margin: 14px 0;
        }
        li {
            margin-bottom: 8px;
        }
        .counts {
            margin-top: 16px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .actions {
            margin-top: 18px;
        }
        .actions a {
            display: inline-block;
            padding: 10px 14px;
            background: #283555;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Users Role Migration Completed</h1>
    <ul>
        <?php foreach ($messages as $message): ?>
            <li><?php echo htmlspecialchars($message, ENT_QUOTES); ?></li>
        <?php endforeach; ?>
    </ul>

    <div class="counts">
        <strong>Current Role Counts</strong>
        <ul>
            <?php if ($roleCounts === []): ?>
                <li>No users found.</li>
            <?php else: ?>
                <?php foreach ($roleCounts as $role => $count): ?>
                    <li><?php echo htmlspecialchars($role, ENT_QUOTES); ?>: <?php echo number_format($count); ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <div class="actions">
        <a href="/CPMraffle/admin_dashboard.php">Back to Dashboard</a>
    </div>
</div>
</body>
</html>