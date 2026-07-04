<?php

declare(strict_types=1);

// One-time OAuth callback helper for Gmail API refresh token setup.
// After obtaining the token, remove or protect this file.

if (!function_exists('ticket_oauth_helper_resolve_credentials_file')) {
    function ticket_oauth_helper_resolve_credentials_file(array $appConfig): string
    {
        $configuredPath = trim((string) ($appConfig['google_drive']['credentials_path'] ?? ($appConfig['gmail_api']['credentials_path'] ?? (__DIR__ . '/jisonbago.json'))));
        $pathsToTry = [];

        if ($configuredPath !== '') {
            $pathsToTry[] = $configuredPath;

            $isAbsolutePath = (bool) preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $configuredPath);
            if (!$isAbsolutePath) {
                $pathsToTry[] = __DIR__ . '/' . ltrim($configuredPath, '\\/');
            }

            $pathsToTry[] = __DIR__ . '/' . basename(str_replace('\\', '/', $configuredPath));
        }

        $pathsToTry[] = __DIR__ . '/jisonbago.json';
        $pathsToTry[] = __DIR__ . '/gdrive.json';
        $pathsToTry = array_values(array_unique($pathsToTry));

        foreach ($pathsToTry as $candidatePath) {
            if (is_file($candidatePath)) {
                return $candidatePath;
            }
        }

        return $configuredPath;
    }
}

if (!function_exists('ticket_oauth_helper_has_scope')) {
    function ticket_oauth_helper_has_scope($scopeField, string $requiredScope): bool
    {
        if (is_array($scopeField)) {
            return in_array($requiredScope, $scopeField, true);
        }

        $raw = trim((string) $scopeField);
        if ($raw === '') {
            return false;
        }

        $parts = preg_split('/\s+/', $raw) ?: [];
        return in_array($requiredScope, $parts, true);
    }
}

$oauthHelperEnabled = false; // Set to true only temporarily when rotating OAuth refresh tokens.
if (!$oauthHelperEnabled) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$oauthHelperKey = trim((string) (getenv('TICKET_OAUTH_HELPER_KEY') ?: ''));
$providedKey = isset($_GET['key']) ? trim((string) $_GET['key']) : '';
if ($oauthHelperKey !== '' && !hash_equals($oauthHelperKey, $providedKey)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$appConfig = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$credentialsFile = ticket_oauth_helper_resolve_credentials_file($appConfig);

if (!is_file($credentialsFile)) {
    http_response_code(500);
    echo 'Credentials file not found: ' . htmlspecialchars($credentialsFile, ENT_QUOTES);
    exit;
}

$raw = file_get_contents($credentialsFile);
$data = json_decode((string) $raw, true);

if (!is_array($data) || !isset($data['web']['client_id'], $data['web']['client_secret'], $data['web']['redirect_uris'][0])) {
    http_response_code(500);
    echo 'Invalid OAuth credentials JSON format.';
    exit;
}

$clientId = (string) $data['web']['client_id'];
$clientSecret = (string) $data['web']['client_secret'];
$redirectUriFromJson = (string) $data['web']['redirect_uris'][0];

$scheme = 'http';
if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) {
    $scheme = 'https';
}

$host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'icto2.psmeinc.org.ph';
$path = isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '/callback.php';
$currentCallbackUrl = $scheme . '://' . $host . $path;

// Priority: explicit env override -> current endpoint URL -> JSON fallback.
$redirectUri = trim((string) (getenv('TICKET_GMAIL_REDIRECT_URI') ?: ''));
if ($redirectUri === '') {
    $redirectUri = $currentCallbackUrl !== '' ? $currentCallbackUrl : $redirectUriFromJson;
}

$authCode = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';

$tokenResponse = null;
$tokenError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exchange_code'])) {
    $postedCode = trim((string) ($_POST['auth_code'] ?? ''));

    if ($postedCode === '') {
        $tokenError = 'Missing authorization code.';
    } else {
        $postData = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $postedCode,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            $tokenError = 'cURL error: ' . $curlError;
        } else {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                $tokenResponse = $decoded;
                if ($status >= 400) {
                    $tokenError = 'Token endpoint returned HTTP ' . $status;
                }
            } else {
                $tokenError = 'Unexpected token response: ' . $result;
            }
        }
    }
}

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/drive.file',
    'access_type' => 'offline',
    'prompt' => 'consent select_account',
    'include_granted_scopes' => 'false',
]);

$requiredGmailScope = 'https://www.googleapis.com/auth/gmail.send';
$requiredDriveScope = 'https://www.googleapis.com/auth/drive.file';
$scopeField = is_array($tokenResponse) ? ($tokenResponse['scope'] ?? '') : '';
$hasGmailScope = ticket_oauth_helper_has_scope($scopeField, $requiredGmailScope);
$hasDriveScope = ticket_oauth_helper_has_scope($scopeField, $requiredDriveScope);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth Callback Helper</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        .box { border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .ok { color: #166534; }
        .err { color: #991b1b; }
        code, pre { background: #f3f4f6; padding: 2px 4px; border-radius: 4px; }
        input[type=text] { width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box; }
        button { padding: 10px 14px; }
    </style>
</head>
<body>
    <h1>Google OAuth Callback Helper</h1>

    <div class="box">
        <p><strong>Step 1:</strong> Start the consent flow using this link:</p>
        <p><a href="<?php echo htmlspecialchars($authUrl, ENT_QUOTES); ?>">Authorize Gmail Send and Google Drive File Scopes</a></p>
        <p><strong>Important:</strong> On the Google consent screen, enable the Drive permission checkbox for this app before continuing.</p>
        <p>Redirect URI in use: <code><?php echo htmlspecialchars($redirectUri, ENT_QUOTES); ?></code></p>
        <p>JSON Redirect URI: <code><?php echo htmlspecialchars($redirectUriFromJson, ENT_QUOTES); ?></code></p>
        <p>Current Callback URL: <code><?php echo htmlspecialchars($currentCallbackUrl, ENT_QUOTES); ?></code></p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="box err">Google returned error: <?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="box">
        <p><strong>Step 2:</strong> Exchange authorization code for tokens.</p>
        <form method="POST">
            <label for="auth_code">Authorization Code</label>
            <input id="auth_code" name="auth_code" type="text" value="<?php echo htmlspecialchars($authCode, ENT_QUOTES); ?>" placeholder="Paste code here">
            <button type="submit" name="exchange_code" value="1">Exchange Code</button>
        </form>
    </div>

    <?php if ($tokenError !== ''): ?>
        <div class="box err"><?php echo htmlspecialchars($tokenError, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if (is_array($tokenResponse)): ?>
        <div class="box">
            <p><strong>Token response:</strong></p>
            <pre><?php echo htmlspecialchars(json_encode($tokenResponse, JSON_PRETTY_PRINT), ENT_QUOTES); ?></pre>

            <p><strong>Granted scope checks:</strong></p>
            <p class="<?php echo $hasGmailScope ? 'ok' : 'err'; ?>">Gmail send scope: <?php echo $hasGmailScope ? 'granted' : 'missing'; ?></p>
            <p class="<?php echo $hasDriveScope ? 'ok' : 'err'; ?>">Drive file scope: <?php echo $hasDriveScope ? 'granted' : 'missing'; ?></p>

            <?php if (!$hasDriveScope): ?>
                <p class="err">Drive scope was not granted. Re-run consent and explicitly enable Drive access on the Google permission screen.</p>
            <?php endif; ?>

            <?php if (!empty($tokenResponse['refresh_token'])): ?>
                <p class="ok"><strong>Refresh token:</strong></p>
                <pre><?php echo htmlspecialchars((string) $tokenResponse['refresh_token'], ENT_QUOTES); ?></pre>
                <p>Set these env vars on your server or save them in <code>ticket_secrets.php</code>:</p>
                <pre>TICKET_GMAIL_REFRESH_TOKEN=<?php echo htmlspecialchars((string) $tokenResponse['refresh_token'], ENT_QUOTES); ?>&#10;TICKET_DRIVE_REFRESH_TOKEN=<?php echo htmlspecialchars((string) $tokenResponse['refresh_token'], ENT_QUOTES); ?></pre>
                <p>For Drive uploads, make sure <code>TICKET_DRIVE_CREDENTIALS_PATH</code> points to your Google OAuth client JSON and <code>TICKET_DRIVE_FOLDER_ID</code> is set to the target folder.</p>
            <?php else: ?>
                <p class="err">No refresh_token returned. Re-run consent with <code>prompt=consent</code> and ensure this client/user pair has not already been granted without offline access.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
