<?php
$appConfig = require __DIR__ . '/config.php';

$databaseConfig = $appConfig['db'];
$primaryHost = trim((string) ($databaseConfig['host'] ?? 'localhost'));
$hostCandidates = array_values(array_unique(array_filter([
    $primaryHost,
    'localhost',
    '127.0.0.1',
], static function ($value): bool {
    return trim((string) $value) !== '';
})));

$dbName = trim((string) ($databaseConfig['database'] ?? 'rafflev2'));
$dbPort = (int) ($databaseConfig['port'] ?? 3306);
$dbSocket = trim((string) ($databaseConfig['socket'] ?? ''));
$connectTimeout = (int) ($databaseConfig['connect_timeout'] ?? 5);
$debugErrors = !empty($databaseConfig['debug_errors']);
if ($connectTimeout <= 0) {
    $connectTimeout = 5;
}
if ($dbPort <= 0) {
    $dbPort = 3306;
}

$credentialCandidates = [];
$configuredUser = trim((string) ($databaseConfig['username'] ?? ''));
$configuredPass = (string) ($databaseConfig['password'] ?? '');
if ($configuredUser !== '') {
    $credentialCandidates[] = [
        'user' => $configuredUser,
        'pass' => $configuredPass,
    ];
}

// Legacy hosted credential fallback for existing deployments.
$credentialCandidates[] = [
    'user' => 'icto_randy_6',
    'pass' => '091902Rands@',
];

// XAMPP-friendly local fallback.
$credentialCandidates[] = [
    'user' => 'root',
    'pass' => '',
];

$credentialCandidates = array_values(array_unique($credentialCandidates, SORT_REGULAR));

$conn = null;
$connectionErrors = [];
foreach ($credentialCandidates as $credential) {
    $dbUser = (string) ($credential['user'] ?? '');
    $dbPass = (string) ($credential['pass'] ?? '');
    if ($dbUser === '') {
        continue;
    }

    foreach ($hostCandidates as $host) {
        try {
            $candidate = mysqli_init();
            if (!$candidate) {
                $connectionErrors[] = 'mysqli_init failed';
                continue;
            }

            $candidate->options(MYSQLI_OPT_CONNECT_TIMEOUT, $connectTimeout);
            $connected = @$candidate->real_connect(
                $host,
                $dbUser,
                $dbPass,
                $dbName,
                $dbPort,
                $dbSocket !== '' ? $dbSocket : null
            );

            if ($connected) {
                $conn = $candidate;
                break 2;
            }

            $connectionErrors[] = $host . ' (' . $dbUser . '): ' . $candidate->connect_error;
            $candidate->close();
        } catch (Throwable $exception) {
            $connectionErrors[] = $host . ' (' . $dbUser . '): ' . $exception->getMessage();
        }
    }
}

if (!$conn instanceof mysqli) {
    error_log('CPMraffle DB connection failed. Attempts: ' . implode(' | ', $connectionErrors));
    http_response_code(500);
    if ($debugErrors) {
        $safeUser = $dbUser !== '' ? $dbUser : '(empty)';
        $safeDb = $dbName !== '' ? $dbName : '(empty)';
        $safeHosts = implode(', ', $hostCandidates);
        exit('Database connection failed. Hosts tried: ' . $safeHosts . '; database: ' . $safeDb . '; user: ' . $safeUser . '.');
    }
    exit('Database connection failed. Please check database configuration.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('ticket_activation_cutoff')) {
    function ticket_activation_cutoff(): DateTimeImmutable
    {
        global $appConfig;

        return new DateTimeImmutable($appConfig['app']['activation_cutoff']);
    }
}

if (!function_exists('ticket_activation_is_closed')) {
    function ticket_activation_is_closed(?DateTimeInterface $currentTime = null): bool
    {
        $now = $currentTime
            ? DateTimeImmutable::createFromInterface($currentTime)
            : new DateTimeImmutable('now');

        return $now > ticket_activation_cutoff();
    }
}

if (!function_exists('ticket_activation_cutoff_label')) {
    function ticket_activation_cutoff_label(): string
    {
        return ticket_activation_cutoff()->format('F j, Y \\a\\t g:i A');
    }
}

if (!function_exists('ticket_activation_closed_message')) {
    function ticket_activation_closed_message(): string
    {
        return 'Deadline Passed - Ticket Void. Ticket activation is closed after ' . ticket_activation_cutoff_label() . '.';
    }
}

?>