<?php

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!function_exists('ticket_drive_guess_mime_type')) {
    function ticket_drive_guess_mime_type(string $filePath, string $fallback = 'application/octet-stream'): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return trim($detected);
                }
            }
        }

        return $fallback;
    }
}

if (!function_exists('ticket_drive_resolve_credentials_path')) {
    function ticket_drive_resolve_credentials_path(array $appConfig): string
    {
        $driveConfig = $appConfig['google_drive'] ?? [];
        $gmailApiConfig = $appConfig['gmail_api'] ?? [];

        $configuredPath = trim((string) ($driveConfig['credentials_path'] ?? ($gmailApiConfig['credentials_path'] ?? (__DIR__ . '/jisonbago.json'))));
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

        throw new RuntimeException('Google credentials file not found. Checked: ' . implode(', ', $pathsToTry));
    }
}

if (!function_exists('ticket_drive_get_client_credentials')) {
    function ticket_drive_get_client_credentials(array $appConfig): array
    {
        $credentialsPath = ticket_drive_resolve_credentials_path($appConfig);
        $rawCredentials = file_get_contents($credentialsPath);
        $credentialsJson = json_decode((string) $rawCredentials, true);
        if (!is_array($credentialsJson) || !isset($credentialsJson['web']['client_id'], $credentialsJson['web']['client_secret'])) {
            throw new RuntimeException('Invalid Google credentials JSON format.');
        }

        return [
            'credentials_path' => $credentialsPath,
            'client_id' => (string) $credentialsJson['web']['client_id'],
            'client_secret' => (string) $credentialsJson['web']['client_secret'],
        ];
    }
}

if (!function_exists('ticket_drive_get_client_credentials_candidates')) {
    function ticket_drive_get_client_credentials_candidates(array $appConfig): array
    {
        $driveConfig = $appConfig['google_drive'] ?? [];
        $gmailApiConfig = $appConfig['gmail_api'] ?? [];

        $configuredPath = trim((string) ($driveConfig['credentials_path'] ?? ($gmailApiConfig['credentials_path'] ?? (__DIR__ . '/jisonbago.json'))));
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

        $credentials = [];
        foreach ($pathsToTry as $candidatePath) {
            if (!is_file($candidatePath)) {
                continue;
            }

            $rawCredentials = file_get_contents($candidatePath);
            $credentialsJson = json_decode((string) $rawCredentials, true);
            if (!is_array($credentialsJson) || !isset($credentialsJson['web']['client_id'], $credentialsJson['web']['client_secret'])) {
                continue;
            }

            $credentials[] = [
                'credentials_path' => $candidatePath,
                'client_id' => (string) $credentialsJson['web']['client_id'],
                'client_secret' => (string) $credentialsJson['web']['client_secret'],
            ];
        }

        if ($credentials === []) {
            throw new RuntimeException('Google credentials file not found or invalid. Checked: ' . implode(', ', $pathsToTry));
        }

        return $credentials;
    }
}

if (!function_exists('ticket_drive_get_refresh_token')) {
    function ticket_drive_get_refresh_token(array $appConfig): string
    {
        $driveConfig = $appConfig['google_drive'] ?? [];
        $gmailApiConfig = $appConfig['gmail_api'] ?? [];
        $refreshToken = trim((string) ($driveConfig['refresh_token'] ?? ($gmailApiConfig['refresh_token'] ?? '')));
        if ($refreshToken === '') {
            throw new RuntimeException('Missing Google Drive refresh token. Set TICKET_DRIVE_REFRESH_TOKEN or TICKET_GMAIL_REFRESH_TOKEN.');
        }

        return $refreshToken;
    }
}

if (!function_exists('ticket_drive_get_refresh_token_candidates')) {
    function ticket_drive_get_refresh_token_candidates(array $appConfig): array
    {
        $driveConfig = $appConfig['google_drive'] ?? [];
        $gmailApiConfig = $appConfig['gmail_api'] ?? [];

        $candidates = [
            trim((string) ($driveConfig['refresh_token'] ?? '')),
            trim((string) ($gmailApiConfig['refresh_token'] ?? '')),
        ];

        $tokens = array_values(array_unique(array_filter($candidates, static function ($value): bool {
            return trim((string) $value) !== '';
        })));

        if ($tokens === []) {
            throw new RuntimeException('Missing Google Drive refresh token. Set TICKET_DRIVE_REFRESH_TOKEN or TICKET_GMAIL_REFRESH_TOKEN.');
        }

        return $tokens;
    }
}

if (!function_exists('ticket_drive_get_folder_id')) {
    function ticket_drive_get_folder_id(array $appConfig): string
    {
        $driveConfig = $appConfig['google_drive'] ?? [];
        return trim((string) ($driveConfig['folder_id'] ?? ''));
    }
}

if (!function_exists('ticket_drive_fetch_access_token_http')) {
    function ticket_drive_fetch_access_token_http(string $clientId, string $clientSecret, string $refreshToken): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Google Drive HTTP fallback.');
        }

        $payload = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Google token request failed: ' . $curlError);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Google token response.');
        }
        if ($status >= 400) {
            $errorCode = trim((string) ($decoded['error'] ?? ''));
            if ($status === 400 && $errorCode === 'invalid_grant') {
                throw new RuntimeException(
                    'Google token endpoint HTTP 400 invalid_grant. Refresh token is expired/revoked, mismatched with OAuth client JSON, or stale env vars are overriding local token values.'
                );
            }

            throw new RuntimeException('Google token endpoint HTTP ' . $status . ': ' . json_encode($decoded));
        }

        $accessToken = trim((string) ($decoded['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Google token response missing access_token.');
        }

        return $accessToken;
    }
}

if (!function_exists('ticket_drive_access_token_scopes_http')) {
    function ticket_drive_access_token_scopes_http(string $accessToken): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $endpoint = 'https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($accessToken);
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return [];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return [];
        }

        $scopeString = trim((string) ($decoded['scope'] ?? ''));
        if ($scopeString === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $scopeString) ?: [];
        return array_values(array_filter($parts, static function ($value): bool {
            return trim((string) $value) !== '';
        }));
    }
}

if (!function_exists('ticket_drive_assert_required_scope')) {
    function ticket_drive_assert_required_scope(string $accessToken): void
    {
        $requiredScope = 'https://www.googleapis.com/auth/drive.file';
        $grantedScopes = ticket_drive_access_token_scopes_http($accessToken);
        if ($grantedScopes === []) {
            return;
        }

        if (!in_array($requiredScope, $grantedScopes, true)) {
            throw new RuntimeException(
                'Google Drive access token is missing drive.file scope. Re-authorize in callback.php and explicitly grant Drive access, then replace TICKET_DRIVE_REFRESH_TOKEN.'
            );
        }
    }
}

if (!function_exists('ticket_drive_upload_file_http')) {
    function ticket_drive_upload_file_http(string $accessToken, string $filename, string $mimeType, string $fileBytes, string $folderId = ''): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Google Drive HTTP fallback.');
        }

        $metadata = ['name' => $filename];
        if ($folderId !== '') {
            $metadata['parents'] = [$folderId];
        }

        $boundary = 'ticket_drive_' . bin2hex(random_bytes(8));
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
        $body .= $fileBytes . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $endpoint = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink';
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Google Drive upload failed: ' . $curlError);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Google Drive upload response.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Google Drive upload endpoint HTTP ' . $status . ': ' . json_encode($decoded));
        }

        $fileId = trim((string) ($decoded['id'] ?? ''));
        if ($fileId === '') {
            throw new RuntimeException('Google Drive upload missing file id.');
        }

        $viewLink = trim((string) ($decoded['webViewLink'] ?? ''));
        if ($viewLink === '') {
            $viewLink = 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
        }

        return [
            'file_id' => $fileId,
            'web_view_link' => $viewLink,
        ];
    }
}

if (!function_exists('ticket_upload_to_google_drive')) {
    function ticket_upload_to_google_drive(array $file, array $appConfig): array
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Proof of payment upload failed.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded proof of payment is invalid.');
        }

        $originalName = basename((string) ($file['name'] ?? 'proof'));
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        if (!is_string($safeName) || trim($safeName) === '' || $safeName === '.' || $safeName === '..') {
            $safeName = 'proof_' . date('YmdHis') . '.bin';
        }

        $mimeType = ticket_drive_guess_mime_type($tmpName, (string) ($file['type'] ?? 'application/octet-stream'));
        $fileBytes = (string) file_get_contents($tmpName);
        if ($fileBytes === '') {
            throw new RuntimeException('Uploaded proof file is empty.');
        }

        $clientCredentialsCandidates = ticket_drive_get_client_credentials_candidates($appConfig);
        $refreshTokens = ticket_drive_get_refresh_token_candidates($appConfig);
        $folderId = ticket_drive_get_folder_id($appConfig);

        $canUseSdk = class_exists(GoogleClient::class)
            && class_exists(Drive::class)
            && class_exists(DriveFile::class);

        $uploadWithCredentialAndToken = static function (array $clientCredentials, string $refreshToken) use ($canUseSdk, $safeName, $folderId, $fileBytes, $mimeType): array {
            if ($canUseSdk) {
                try {
                    $client = new GoogleClient();
                    $client->setAuthConfig((string) $clientCredentials['credentials_path']);
                    $client->setAccessType('offline');
                    $client->addScope(Drive::DRIVE_FILE);
                    $fetchedToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    $sdkAccessToken = trim((string) (($fetchedToken['access_token'] ?? '') ?: (($client->getAccessToken()['access_token'] ?? ''))));
                    if ($sdkAccessToken !== '') {
                        ticket_drive_assert_required_scope($sdkAccessToken);
                    }

                    $service = new Drive($client);
                    $metadata = new DriveFile([
                        'name' => $safeName,
                    ]);
                    if ($folderId !== '') {
                        $metadata->setParents([$folderId]);
                    }

                    $uploaded = $service->files->create($metadata, [
                        'data' => $fileBytes,
                        'mimeType' => $mimeType,
                        'uploadType' => 'multipart',
                        'fields' => 'id,webViewLink',
                    ]);

                    $fileId = trim((string) $uploaded->getId());
                    if ($fileId === '') {
                        throw new RuntimeException('Google Drive upload missing file id.');
                    }

                    $viewLink = trim((string) ($uploaded->getWebViewLink() ?? ''));
                    if ($viewLink === '') {
                        $viewLink = 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
                    }

                    return [
                        'file_id' => $fileId,
                        'web_view_link' => $viewLink,
                        'filename' => $safeName,
                        'mime_type' => $mimeType,
                    ];
                } catch (Throwable $sdkError) {
                    $accessToken = ticket_drive_fetch_access_token_http((string) $clientCredentials['client_id'], (string) $clientCredentials['client_secret'], $refreshToken);
                    ticket_drive_assert_required_scope($accessToken);
                    $fallback = ticket_drive_upload_file_http($accessToken, $safeName, $mimeType, $fileBytes, $folderId);
                    return [
                        'file_id' => (string) ($fallback['file_id'] ?? ''),
                        'web_view_link' => (string) ($fallback['web_view_link'] ?? ''),
                        'filename' => $safeName,
                        'mime_type' => $mimeType,
                    ];
                }
            }

            $accessToken = ticket_drive_fetch_access_token_http((string) $clientCredentials['client_id'], (string) $clientCredentials['client_secret'], $refreshToken);
            ticket_drive_assert_required_scope($accessToken);
            $fallback = ticket_drive_upload_file_http($accessToken, $safeName, $mimeType, $fileBytes, $folderId);

            return [
                'file_id' => (string) ($fallback['file_id'] ?? ''),
                'web_view_link' => (string) ($fallback['web_view_link'] ?? ''),
                'filename' => $safeName,
                'mime_type' => $mimeType,
            ];
        };

        $lastError = null;
        foreach ($clientCredentialsCandidates as $clientCredentials) {
            foreach ($refreshTokens as $refreshToken) {
                try {
                    return $uploadWithCredentialAndToken($clientCredentials, $refreshToken);
                } catch (Throwable $error) {
                    $lastError = $error;
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw new RuntimeException('Google Drive upload failed for all configured refresh tokens. Last error: ' . $lastError->getMessage(), 0, $lastError);
        }

        throw new RuntimeException('Google Drive upload failed: no usable refresh token candidate.');
    }
}
