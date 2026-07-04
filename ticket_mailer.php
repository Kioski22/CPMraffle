<?php

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;

require_once __DIR__ . '/email_composer.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!function_exists('ticket_base64url_encode')) {
    function ticket_base64url_encode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}

if (!function_exists('ticket_mail_error_is_transient')) {
    function ticket_mail_error_is_transient(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        $markers = [
            'connection was reset',
            'timed out',
            'timeout',
            'temporarily unavailable',
            'could not resolve host',
            'failed to connect',
            'network',
            'ssl connect error',
            'connection refused',
            'connection aborted',
            'stream error',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
        ];

        foreach ($markers as $marker) {
            if (strpos($message, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ticket_retry_transient')) {
    function ticket_retry_transient(callable $callback, int $maxAttempts = 3)
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                return $callback();
            } catch (Throwable $error) {
                $lastError = $error;
                $canRetry = $attempt < $maxAttempts && ticket_mail_error_is_transient($error);
                if (!$canRetry) {
                    throw $error;
                }

                // Short exponential backoff for transient transport errors.
                usleep((int) (200000 * $attempt));
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new RuntimeException('Mail send failed without an error message.');
    }
}

if (!function_exists('ticket_gmail_fetch_access_token_http')) {
    function ticket_gmail_fetch_access_token_http(string $clientId, string $clientSecret, string $refreshToken): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Gmail API HTTP fallback.');
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
            throw new RuntimeException('Gmail token request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Gmail token response.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Gmail token endpoint HTTP ' . $status . ': ' . json_encode($decoded));
        }

        $accessToken = trim((string) ($decoded['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Gmail token response missing access_token.');
        }

        return $accessToken;
    }
}

if (!function_exists('ticket_gmail_send_message_http')) {
    function ticket_gmail_send_message_http(string $accessToken, string $userId, string $rawMessage): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Gmail API HTTP fallback.');
        }

        $endpoint = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($userId) . '/messages/send';
        $body = json_encode(['raw' => $rawMessage]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gmail send request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($status >= 400) {
            throw new RuntimeException('Gmail send endpoint HTTP ' . $status . ': ' . json_encode($decoded));
        }
    }
}

if (!function_exists('ticket_send_gmail_message')) {
    function ticket_send_gmail_message(array $appConfig, string $toName, string $toEmail, array $emailParts): string
    {
        $gmailApiConfig = $appConfig['gmail_api'] ?? [];
        $driveConfig = $appConfig['google_drive'] ?? [];
        $mailConfig = $appConfig['mail'] ?? [];
        $configuredCredentialsPath = trim((string) ($gmailApiConfig['credentials_path'] ?? (__DIR__ . '/jisonbago.json')));
        $pathsToTry = [];

        if ($configuredCredentialsPath !== '') {
            $pathsToTry[] = $configuredCredentialsPath;

            // Resolve relative paths against this project directory.
            $isAbsolutePath = (bool) preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $configuredCredentialsPath);
            if (!$isAbsolutePath) {
                $pathsToTry[] = __DIR__ . '/' . ltrim($configuredCredentialsPath, '\\/');
            }

            // Allow fallback to the local file when a stale absolute path is configured.
            $pathsToTry[] = __DIR__ . '/' . basename(str_replace('\\', '/', $configuredCredentialsPath));
        }

        $pathsToTry[] = __DIR__ . '/jisonbago.json';
        $pathsToTry[] = __DIR__ . '/gdrive.json';
        $pathsToTry = array_values(array_unique($pathsToTry));

        $credentialsCandidates = [];
        foreach ($pathsToTry as $candidatePath) {
            if (!is_file($candidatePath)) {
                continue;
            }

            $rawCredentials = file_get_contents($candidatePath);
            $credentialsJson = json_decode((string) $rawCredentials, true);
            if (!is_array($credentialsJson) || !isset($credentialsJson['web']['client_id'], $credentialsJson['web']['client_secret'])) {
                continue;
            }

            $credentialsCandidates[] = [
                'credentials_path' => $candidatePath,
                'client_id' => (string) $credentialsJson['web']['client_id'],
                'client_secret' => (string) $credentialsJson['web']['client_secret'],
            ];
        }

        if ($credentialsCandidates === []) {
            throw new RuntimeException('Gmail API credentials file not found. Checked: ' . implode(', ', $pathsToTry));
        }

        $refreshTokenCandidates = array_values(array_unique(array_filter([
            trim((string) ($gmailApiConfig['refresh_token'] ?? '')),
            trim((string) ($driveConfig['refresh_token'] ?? '')),
        ], static function ($value): bool {
            return trim((string) $value) !== '';
        })));

        if ($refreshTokenCandidates === []) {
            throw new RuntimeException('Missing Gmail API refresh token. Set TICKET_GMAIL_REFRESH_TOKEN.');
        }
        $userId = trim((string) ($gmailApiConfig['user_id'] ?? 'me'));
        if ($userId === '') {
            $userId = 'me';
        }

        $fromAddress = $mailConfig['from_address'] ?? 'no-reply@example.com';
        $fromName = $mailConfig['from_name'] ?? 'Ticketing Team';
        $inlineImages = (array) ($emailParts['inline_images'] ?? []);
        $attachments = (array) ($emailParts['attachments'] ?? []);
        $mimeMessage = ticket_build_mime_message(
            $fromAddress,
            $fromName,
            $toEmail,
            $toName,
            (string) ($emailParts['subject'] ?? ''),
            (string) ($emailParts['body'] ?? ''),
            (string) ($emailParts['content_type'] ?? 'text/html; charset=UTF-8'),
            $inlineImages,
            $attachments
        );
        $rawMessage = ticket_base64url_encode($mimeMessage);

        $canUseGmailApi = class_exists(GoogleClient::class)
            && class_exists(Gmail::class)
            && class_exists(GmailMessage::class);

        $sendWithCredentialAndToken = static function (array $credentials, string $refreshToken) use ($canUseGmailApi, $rawMessage, $userId, $emailParts): string {
            $credentialsPath = (string) ($credentials['credentials_path'] ?? '');
            $clientId = (string) ($credentials['client_id'] ?? '');
            $clientSecret = (string) ($credentials['client_secret'] ?? '');

            if ($credentialsPath === '' || $clientId === '' || $clientSecret === '') {
                throw new RuntimeException('Invalid Gmail credentials candidate.');
            }

            if ($canUseGmailApi) {
                try {
                    ticket_retry_transient(function () use ($credentialsPath, $refreshToken, $rawMessage, $userId): void {
                        $client = new GoogleClient();
                        $client->setAuthConfig($credentialsPath);
                        $client->setAccessType('offline');
                        $client->addScope(Gmail::GMAIL_SEND);
                        $client->fetchAccessTokenWithRefreshToken($refreshToken);

                        $gmail = new Gmail($client);
                        $gmailMessage = new GmailMessage();
                        $gmailMessage->setRaw($rawMessage);
                        $gmail->users_messages->send($userId, $gmailMessage);
                    }, 3);

                    return 'Gmail API (' . strtoupper((string) ($emailParts['format'] ?? 'html')) . ')';
                } catch (Throwable $gmailApiError) {
                    $accessToken = ticket_retry_transient(function () use ($clientId, $clientSecret, $refreshToken): string {
                        return ticket_gmail_fetch_access_token_http($clientId, $clientSecret, $refreshToken);
                    }, 3);

                    try {
                        ticket_retry_transient(function () use ($accessToken, $userId, $rawMessage): void {
                            ticket_gmail_send_message_http($accessToken, $userId, $rawMessage);
                        }, 3);
                    } catch (Throwable $httpFallbackError) {
                        throw new RuntimeException(
                            'Gmail API send failed (SDK then HTTP fallback). SDK: ' . $gmailApiError->getMessage()
                            . ' | HTTP: ' . $httpFallbackError->getMessage(),
                            0,
                            $httpFallbackError
                        );
                    }

                    return 'Gmail API (HTTP Fallback after SDK error, ' . strtoupper((string) ($emailParts['format'] ?? 'html')) . ')';
                }
            }

            $accessToken = ticket_retry_transient(function () use ($clientId, $clientSecret, $refreshToken): string {
                return ticket_gmail_fetch_access_token_http($clientId, $clientSecret, $refreshToken);
            }, 3);
            ticket_retry_transient(function () use ($accessToken, $userId, $rawMessage): void {
                ticket_gmail_send_message_http($accessToken, $userId, $rawMessage);
            }, 3);

            return 'Gmail API (HTTP Fallback, ' . strtoupper((string) ($emailParts['format'] ?? 'html')) . ')';
        };

        $lastError = null;
        foreach ($credentialsCandidates as $credentials) {
            foreach ($refreshTokenCandidates as $refreshToken) {
                try {
                    return $sendWithCredentialAndToken($credentials, $refreshToken);
                } catch (Throwable $error) {
                    $lastError = $error;
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw new RuntimeException('Gmail send failed for all configured credentials/token combinations. Last error: ' . $lastError->getMessage(), 0, $lastError);
        }

        throw new RuntimeException('Gmail send failed: no usable credentials/token combination.');
    }
}

if (!function_exists('ticket_send_chapter_invitation')) {
    function ticket_send_chapter_invitation(array $appConfig, array $input): string
    {
        $chapterName = trim((string) ($input['chapter_name'] ?? ''));
        $presidentEmail = trim((string) ($input['president_email'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = trim((string) ($input['password'] ?? ''));
        $ticketRangeSummary = trim((string) ($input['ticket_range_summary'] ?? 'Pending allocation'));
        $ticketCount = trim((string) ($input['ticket_count'] ?? '0'));

        if ($chapterName === '') {
            throw new RuntimeException('Chapter name is required for chapter invitation.');
        }
        if ($presidentEmail === '' || !filter_var($presidentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Valid president email is required for chapter invitation.');
        }
        if ($username === '') {
            $username = $presidentEmail;
        }

        $templateData = ticket_build_chapter_invitation_template_data([
            'chapter_name' => $chapterName,
            'president_email' => $presidentEmail,
            'username' => $username,
            'password' => $password,
            'portal_link' => ticket_chapter_portal_login_link($appConfig),
            'ticket_range_summary' => $ticketRangeSummary,
            'ticket_count' => $ticketCount,
            'app_name' => (string) ($appConfig['mail']['from_name'] ?? 'Ticketing Team'),
        ]);
        $emailParts = ticket_compose_chapter_invitation_email($appConfig, $templateData);

        return ticket_send_gmail_message($appConfig, $chapterName, $presidentEmail, $emailParts);
    }
}
