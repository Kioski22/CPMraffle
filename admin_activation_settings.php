<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'cpmadmin']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';
require_once __DIR__ . '/buyer_activation_helper.php';

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

if (!isset($_SESSION['activation_settings_csrf'])) {
    $_SESSION['activation_settings_csrf'] = bin2hex(random_bytes(32));
}

$flashMessage = '';
$flashType = 'success';

$unitMultipliers = [
    'minutes' => 60,
    'hours' => 3600,
    'days' => 86400,
];

$defaultTtl = ticket_activation_token_ttl($appConfig);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['activation_settings_csrf'], $csrf)) {
            throw new RuntimeException('Invalid settings request. Please refresh and try again.');
        }

        $ttlValue = (int) ($_POST['ttl_value'] ?? 0);
        $ttlUnit = strtolower(trim((string) ($_POST['ttl_unit'] ?? 'days')));
        if (!array_key_exists($ttlUnit, $unitMultipliers)) {
            $ttlUnit = 'days';
        }

        if ($ttlValue <= 0) {
            throw new RuntimeException('Expiration value must be greater than zero.');
        }

        $ttlSeconds = $ttlValue * $unitMultipliers[$ttlUnit];
        if ($ttlSeconds < 300 || $ttlSeconds > 31536000) {
            throw new RuntimeException('Expiration must be between 5 minutes and 365 days.');
        }

        $stmt = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if (!$stmt) {
            throw new RuntimeException('Unable to save activation settings right now.');
        }

        $settingKey = 'token_ttl_seconds';
        $settingValue = (string) $ttlSeconds;
        $stmt->bind_param('ss', $settingKey, $settingValue);
        $stmt->execute();
        $stmt->close();

        $flashMessage = 'Activation link expiration updated successfully.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$currentTtlSeconds = ticket_activation_token_ttl($appConfig, $conn);
$currentDays = $currentTtlSeconds / 86400;
$currentHours = $currentTtlSeconds / 3600;
$currentMinutes = $currentTtlSeconds / 60;

$currentLabel = $currentTtlSeconds . ' seconds';
if ($currentTtlSeconds % 86400 === 0) {
    $wholeDays = (int) ($currentTtlSeconds / 86400);
    $currentLabel = $wholeDays . ' day' . ($wholeDays === 1 ? '' : 's');
} elseif ($currentTtlSeconds % 3600 === 0) {
    $wholeHours = (int) ($currentTtlSeconds / 3600);
    $currentLabel = $wholeHours . ' hour' . ($wholeHours === 1 ? '' : 's');
} elseif ($currentTtlSeconds % 60 === 0) {
    $wholeMinutes = (int) ($currentTtlSeconds / 60);
    $currentLabel = $wholeMinutes . ' minute' . ($wholeMinutes === 1 ? '' : 's');
}

$formUnit = 'days';
$formValue = max(1, (int) round($currentDays));
if ($currentTtlSeconds % 86400 === 0) {
    $formUnit = 'days';
    $formValue = max(1, (int) ($currentTtlSeconds / 86400));
} elseif ($currentTtlSeconds % 3600 === 0) {
    $formUnit = 'hours';
    $formValue = max(1, (int) ($currentTtlSeconds / 3600));
} else {
    $formUnit = 'minutes';
    $formValue = max(1, (int) round($currentMinutes));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activation Settings</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            margin: 0;
            background: linear-gradient(160deg, #eef2ff 0%, #f9fafb 45%, #ecfeff 100%);
            color: #1f2937;
            min-height: 100vh;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .content {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 28px;
        }

        .panel {
            max-width: 780px;
            background: #ffffff;
            border: 1px solid #dbe4f0;
            border-radius: 16px;
            box-shadow: 0 18px 48px rgba(40, 53, 85, 0.12);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, #1f3c68 0%, #2d5a92 100%);
            color: #ffffff;
            padding: 20px 24px;
        }

        .panel-header h1 {
            margin: 0 0 6px;
            font-size: 1.55rem;
        }

        .panel-header p {
            margin: 0;
            font-size: 0.95rem;
            color: #dbeafe;
        }

        .panel-body {
            padding: 22px 24px 24px;
        }

        .meta-box {
            border: 1px solid #dbe4f0;
            background: #f8fbff;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .meta-item {
            background: #ffffff;
            border: 1px solid #e5edf7;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .meta-item strong {
            display: block;
            color: #1f3c68;
            margin-bottom: 4px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .form-row {
            display: grid;
            grid-template-columns: minmax(120px, 220px) minmax(130px, 180px);
            gap: 10px;
            align-items: end;
            margin-bottom: 10px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #1f3c68;
        }

        input[type='number'],
        select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cfd8e6;
            border-radius: 10px;
            font-size: 0.95rem;
        }

        .hint {
            color: #4b5563;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            background: #ff6b35;
            color: #ffffff;
            padding: 10px 16px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
        }

        .btn:hover {
            background: #e95b28;
        }

        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }

        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        @media (max-width: 900px) {
            .content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <?php render_dashboard_sidebar('/CPMraffle/admin_activation_settings.php', $isAdmin, $dashboardPath); ?>

    <main class="content">
        <section class="panel">
            <div class="panel-header">
                <h1>Activation Link Expiration</h1>
                <p>Changes apply immediately to all pending activation links, including existing links.</p>
            </div>

            <div class="panel-body">
                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div class="meta-box">
                    <strong>Current Expiration:</strong> <?php echo htmlspecialchars($currentLabel, ENT_QUOTES); ?>
                    <div class="meta-grid">
                        <div class="meta-item">
                            <strong>Minutes</strong>
                            <span><?php echo number_format($currentMinutes, 2); ?></span>
                        </div>
                        <div class="meta-item">
                            <strong>Hours</strong>
                            <span><?php echo number_format($currentHours, 2); ?></span>
                        </div>
                        <div class="meta-item">
                            <strong>Days</strong>
                            <span><?php echo number_format($currentDays, 2); ?></span>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['activation_settings_csrf'], ENT_QUOTES); ?>">
                    <div class="form-row">
                        <div>
                            <label for="ttl_value">Expiration Value</label>
                            <input type="number" id="ttl_value" name="ttl_value" min="1" step="1" value="<?php echo (int) $formValue; ?>" required>
                        </div>
                        <div>
                            <label for="ttl_unit">Unit</label>
                            <select id="ttl_unit" name="ttl_unit">
                                <option value="minutes" <?php echo $formUnit === 'minutes' ? 'selected' : ''; ?>>Minutes</option>
                                <option value="hours" <?php echo $formUnit === 'hours' ? 'selected' : ''; ?>>Hours</option>
                                <option value="days" <?php echo $formUnit === 'days' ? 'selected' : ''; ?>>Days</option>
                            </select>
                        </div>
                    </div>
                    <p class="hint">Allowed range: 5 minutes to 365 days.</p>
                    <button type="submit" class="btn">Save Activation Expiration</button>
                </form>
            </div>
        </section>
    </main>
</div>
</body>
</html>
