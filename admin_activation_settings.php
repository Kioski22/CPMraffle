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

$currentExpirationTimestamp = ticket_activation_expiration_timestamp($appConfig, $conn);
$currentExpirationDate = $currentExpirationTimestamp !== null ? date('Y-m-d', $currentExpirationTimestamp) : '';
$currentExpirationLabel = $currentExpirationTimestamp !== null
    ? date('F j, Y g:i A', $currentExpirationTimestamp)
    : 'Not set';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['activation_settings_csrf'], $csrf)) {
            throw new RuntimeException('Invalid settings request. Please refresh and try again.');
        }

        $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
        if ($expirationDate === '') {
            throw new RuntimeException('Please select an expiration date.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDate)) {
            throw new RuntimeException('Please provide a valid expiration date.');
        }

        $expirationTimestamp = strtotime($expirationDate . ' 23:59:59');
        if ($expirationTimestamp === false) {
            throw new RuntimeException('Unable to parse the selected expiration date.');
        }

        // Keep the selected day usable until end of day in app timezone.
        if ($expirationTimestamp <= time()) {
            throw new RuntimeException('Expiration date must be later than today.');
        }

        $stmt = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if (!$stmt) {
            throw new RuntimeException('Unable to save activation settings right now.');
        }

        $settingKey = 'activation_links_expire_at';
        $settingValue = date('Y-m-d H:i:s', $expirationTimestamp);
        $stmt->bind_param('ss', $settingKey, $settingValue);
        $stmt->execute();
        $stmt->close();

        $flashMessage = 'Activation link expiration date updated successfully.';
        $flashType = 'success';

        $currentExpirationTimestamp = $expirationTimestamp;
        $currentExpirationDate = date('Y-m-d', $expirationTimestamp);
        $currentExpirationLabel = date('F j, Y g:i A', $expirationTimestamp);
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$formDateValue = $currentExpirationDate !== '' ? $currentExpirationDate : date('Y-m-d', strtotime('+7 days'));
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
            grid-template-columns: minmax(220px, 320px);
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

        input[type='date'] {
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
                <p>Set one date when all unactivated activation links should expire.</p>
            </div>

            <div class="panel-body">
                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div class="meta-box">
                    <strong>Current Expiration Date:</strong> <?php echo htmlspecialchars($currentExpirationLabel, ENT_QUOTES); ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['activation_settings_csrf'], ENT_QUOTES); ?>">
                    <div class="form-row">
                        <div>
                            <label for="expiration_date">Expiration Date</label>
                            <input type="date" id="expiration_date" name="expiration_date" value="<?php echo htmlspecialchars($formDateValue, ENT_QUOTES); ?>" required>
                        </div>
                    </div>
                    <p class="hint">All currently unactivated links will become invalid after 11:59:59 PM of the selected date.</p>
                    <button type="submit" class="btn">Save Expiration Date</button>
                </form>
            </div>
        </section>
    </main>
</div>
</body>
</html>
