<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

include __DIR__ . '/db.php';

function migration_run_alter(mysqli $conn, string $sql): array
{
    if ($conn->query($sql)) {
        return ['status' => 'added', 'message' => 'Column added successfully.'];
    }

    $error = strtolower((string) $conn->error);
    if (strpos($error, 'duplicate column') !== false) {
        return ['status' => 'exists', 'message' => 'Column already exists.'];
    }

    return ['status' => 'failed', 'message' => $conn->error !== '' ? $conn->error : 'Unknown database error.'];
}

$results = [];
$results['buyers.remarks'] = migration_run_alter(
    $conn,
    'ALTER TABLE buyers ADD COLUMN remarks VARCHAR(255) DEFAULT NULL'
);
$results['users.remarks'] = migration_run_alter(
    $conn,
    'ALTER TABLE users ADD COLUMN remarks VARCHAR(255) DEFAULT NULL'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remarks Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .wrap { max-width: 800px; margin: 30px auto; }
        .card { border-radius: 14px; }
        .status-added { color: #166534; }
        .status-exists { color: #92400e; }
        .status-failed { color: #991b1b; }
    </style>
</head>
<body>
<div class="container wrap">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Remarks Column Migration</h1>
            <p class="text-muted mb-4">This script applies one-time schema updates for buyer and chapter remarks.</p>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $target => $result): ?>
                        <?php $status = (string) ($result['status'] ?? 'failed'); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($target, ENT_QUOTES); ?></td>
                            <td class="status-<?php echo htmlspecialchars($status, ENT_QUOTES); ?> fw-semibold"><?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string) ($result['message'] ?? ''), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info mt-4 mb-0">
                After confirming success, remove this file from production for security hygiene.
            </div>
        </div>
    </div>
</div>
</body>
</html>
