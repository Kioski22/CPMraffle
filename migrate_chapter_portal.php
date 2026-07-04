<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);

include 'db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_template_store.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$messages = [];
$lockFile = __DIR__ . '/uploads/.chapter_portal_migration_done.lock';
$forceRun = isset($_GET['force']) && $_GET['force'] === '1';

if (is_file($lockFile) && !$forceRun) {
    http_response_code(403);
    echo '<h2>Chapter Portal Migration Locked</h2>';
    echo '<p>This migration has already been applied and is locked.</p>';
    echo '<p>If you need to rerun it as admin, open: <code>/CPMraffle/migrate_chapter_portal.php?force=1</code></p>';
    exit();
}

function migration_table_exists(mysqli $conn, string $tableName): bool
{
    $escapedTable = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");
    if ($result instanceof mysqli_result) {
        $exists = $result->num_rows > 0;
        $result->close();
        return $exists;
    }

    return false;
}

function migration_add_column_if_missing(mysqli $conn, string $tableName, string $columnDefinition): bool
{
    [$columnName] = explode(' ', $columnDefinition, 2);
    $escapedTable = $conn->real_escape_string($tableName);
    $escapedColumn = $conn->real_escape_string($columnName);
    $columnCheck = $conn->query("SHOW COLUMNS FROM {$escapedTable} LIKE '{$escapedColumn}'");
    if (!$columnCheck instanceof mysqli_result) {
        return false;
    }

    $needsColumn = $columnCheck->num_rows === 0;
    $columnCheck->close();
    if ($needsColumn) {
        $conn->query("ALTER TABLE {$escapedTable} ADD COLUMN {$columnDefinition}");
        return true;
    }

    return false;
}

try {
    ensureBuyerSchema($conn);
    ensureEmailTemplateTables($conn, $appConfig);

    if (migration_table_exists($conn, 'users')) {
        if (migration_add_column_if_missing($conn, 'users', "role VARCHAR(20) NOT NULL DEFAULT 'admin'")) {
            $messages[] = 'Added role column to users table.';
        } else {
            $messages[] = 'Role column already exists on users table.';
        }

        if (migration_add_column_if_missing($conn, 'users', 'email VARCHAR(150) NULL')) {
            $messages[] = 'Added email column to users table.';
        } else {
            $messages[] = 'Email column already exists on users table.';
        }

        if (migration_add_column_if_missing($conn, 'users', 'chapter_name VARCHAR(120) NULL')) {
            $messages[] = 'Added chapter_name column to users table.';
        } else {
            $messages[] = 'chapter_name column already exists on users table.';
        }

        $normalizeStmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE LOWER(TRIM(COALESCE(role, ''))) NOT IN ('admin', 'secretariat', 'chapter')");
        if ($normalizeStmt) {
            $normalizeStmt->execute();
            $normalized = $normalizeStmt->affected_rows;
            $normalizeStmt->close();
            $messages[] = 'Normalized invalid user roles to admin: ' . max(0, (int) $normalized) . ' user(s).';
        }
    } else {
        throw new RuntimeException('Users table does not exist.');
    }

    if (migration_table_exists($conn, 'tickets')) {
        if (migration_add_column_if_missing($conn, 'tickets', 'assigned_chapter_id INT(11) NULL')) {
            $messages[] = 'Added assigned_chapter_id column to tickets table.';
        } else {
            $messages[] = 'assigned_chapter_id column already exists on tickets table.';
        }

        if (migration_add_column_if_missing($conn, 'tickets', 'chapter_assigned_at DATETIME NULL')) {
            $messages[] = 'Added chapter_assigned_at column to tickets table.';
        } else {
            $messages[] = 'chapter_assigned_at column already exists on tickets table.';
        }

        $chapterTicketIndex = $conn->query("SHOW INDEX FROM tickets WHERE Key_name = 'idx_tickets_assigned_chapter_id'");
        if ($chapterTicketIndex instanceof mysqli_result && $chapterTicketIndex->num_rows === 0) {
            $conn->query('ALTER TABLE tickets ADD KEY idx_tickets_assigned_chapter_id (assigned_chapter_id)');
            $messages[] = 'Added index idx_tickets_assigned_chapter_id.';
        } else {
            $messages[] = 'Index idx_tickets_assigned_chapter_id already exists.';
        }
        if ($chapterTicketIndex instanceof mysqli_result) {
            $chapterTicketIndex->close();
        }
    } else {
        throw new RuntimeException('Tickets table does not exist.');
    }

    $conn->query("CREATE TABLE IF NOT EXISTS chapter_allocations (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        chapter_id INT(11) NOT NULL,
        admin_id INT(11) NOT NULL,
        start_ticket VARCHAR(80) NOT NULL,
        end_ticket VARCHAR(80) NOT NULL,
        allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chapter_allocations_chapter_id (chapter_id),
        KEY idx_chapter_allocations_admin_id (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = 'Ensured chapter_allocations table exists.';

    $lockWritten = @file_put_contents($lockFile, 'completed_at=' . date(DATE_ATOM) . PHP_EOL);
    if ($lockWritten === false) {
        $messages[] = 'Warning: migration completed but lock file could not be written.';
    } else {
        $messages[] = 'Migration lock enabled.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Chapter Portal Migration Failed</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chapter Portal Migration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            color: #1f2937;
            margin: 0;
            padding: 30px;
        }
        .wrap {
            max-width: 760px;
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
    <h1>Chapter Portal Migration Completed</h1>
    <ul>
        <?php foreach ($messages as $message): ?>
            <li><?php echo htmlspecialchars($message, ENT_QUOTES); ?></li>
        <?php endforeach; ?>
    </ul>

    <div class="actions">
        <a href="/CPMraffle/admin_dashboard.php">Back to Dashboard</a>
    </div>
</div>
</body>
</html>