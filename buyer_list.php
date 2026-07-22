<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

$canManageBuyers = auth_is_admin();
$isAdmin = auth_is_admin();
$canResendEmail = auth_is_admin() || auth_is_secretariat();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

include 'db.php';
include 'config.php';
require_once 'buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;

require_once __DIR__ . '/vendor/autoload.php';

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}

if (!function_exists('gmail_fetch_access_token_http')) {
    function gmail_fetch_access_token_http(string $clientId, string $clientSecret, string $refreshToken): string
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

if (!function_exists('gmail_send_message_http')) {
    function gmail_send_message_http(string $accessToken, string $userId, string $rawMessage): void
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

if (!function_exists('smtp_read_line')) {
    function smtp_read_line($socket): string
    {
        $line = '';
        while (!feof($socket)) {
            $chunk = fgets($socket, 515);
            if ($chunk === false) {
                break;
            }
            $line = $chunk;
            // SMTP multiline responses continue while 4th char is '-'.
            if (strlen($chunk) < 4 || $chunk[3] !== '-') {
                break;
            }
        }

        return trim($line);
    }
}

if (!function_exists('smtp_expect_code')) {
    function smtp_expect_code($socket, array $allowedCodes): string
    {
        $response = smtp_read_line($socket);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $allowedCodes, true)) {
            throw new RuntimeException('SMTP error: ' . $response);
        }

        return $response;
    }
}

if (!function_exists('smtp_send_command')) {
    function smtp_send_command($socket, string $command, array $allowedCodes): string
    {
        fwrite($socket, $command . "\r\n");

        return smtp_expect_code($socket, $allowedCodes);
    }
}

if (!function_exists('smtp_send_html_mail')) {
    function smtp_send_html_mail(array $mailConfig, string $fromAddress, string $fromName, string $toAddress, string $toName, string $subject, string $htmlBody): void
    {
        $host = (string) ($mailConfig['host'] ?? 'smtp.gmail.com');
        $port = (int) ($mailConfig['port'] ?? 587);
        $username = (string) ($mailConfig['username'] ?? '');
        $password = (string) ($mailConfig['password'] ?? '');
        $encryption = strtolower((string) ($mailConfig['encryption'] ?? 'tls'));

        if ($username === '' || $password === '') {
            throw new RuntimeException('SMTP username/password is missing.');
        }

        $transportHost = $encryption === 'ssl' ? ('ssl://' . $host) : $host;
        $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 20);
        if (!$socket) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 20);

        try {
            smtp_expect_code($socket, [220]);
            smtp_send_command($socket, 'EHLO icto2.psmeinc.org.ph', [250]);

            if ($encryption === 'tls') {
                smtp_send_command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Unable to enable STARTTLS for SMTP connection.');
                }
                smtp_send_command($socket, 'EHLO icto2.psmeinc.org.ph', [250]);
            }

            smtp_send_command($socket, 'AUTH LOGIN', [334]);
            smtp_send_command($socket, base64_encode($username), [334]);
            smtp_send_command($socket, base64_encode($password), [235]);

            smtp_send_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
            smtp_send_command($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251]);
            smtp_send_command($socket, 'DATA', [354]);

            $safeFromName = str_replace(["\r", "\n"], '', $fromName);
            $safeToName = str_replace(["\r", "\n"], '', $toName);
            $headers = [];
            $headers[] = 'Date: ' . date(DATE_RFC2822);
            $headers[] = 'From: ' . $safeFromName . ' <' . $fromAddress . '>';
            $headers[] = 'To: ' . $safeToName . ' <' . $toAddress . '>';
            $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
            fwrite($socket, $data . "\r\n");
            smtp_expect_code($socket, [250]);
            smtp_send_command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }
}

ensureBuyerSchema($conn);

$flashMessage = '';
$flashType = 'success';
$allowedRemarks = ['', 'Paid', 'Fully Paid', 'Partially Paid', 'Pending', 'Not Paid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'update_buyer' && !$canManageBuyers) {
            throw new RuntimeException('You do not have permission to update buyer records.');
        }
        if ($action === 'update_remarks' && !$canManageBuyers) {
            throw new RuntimeException('You do not have permission to update buyer remarks.');
        }
        if ($action === 'send_email' && !$canResendEmail) {
            throw new RuntimeException('You do not have permission to resend buyer emails.');
        }

        if ($action === 'update_buyer') {
            $buyerId = (int) ($_POST['buyer_id'] ?? 0);
            $buyerType = strtolower(trim((string) ($_POST['buyer_type'] ?? '')));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $contact = trim((string) ($_POST['contact'] ?? ''));
            $chapter = trim((string) ($_POST['chapter'] ?? ''));
            $status = strtolower(trim((string) ($_POST['status'] ?? 'pending')));

            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid buyer selected.');
            }
            if (!in_array($buyerType, ['individual', 'chapter', 'bulk'], true)) {
                throw new RuntimeException('Invalid buyer type.');
            }
            if ($fullName === '' || $email === '' || $contact === '' || $chapter === '') {
                throw new RuntimeException('All buyer fields are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please provide a valid email address.');
            }
            if ($status === '') {
                throw new RuntimeException('Status is required.');
            }

            $updateBuyer = $conn->prepare("UPDATE buyers
                SET buyer_type = ?, full_name = ?, email = ?, contact = ?, chapter = ?, status = ?, updated_at = NOW()
                WHERE id = ?");
            if (!$updateBuyer) {
                throw new RuntimeException('Unable to update buyer details.');
            }

            $updateBuyer->bind_param('ssssssi', $buyerType, $fullName, $email, $contact, $chapter, $status, $buyerId);
            $updateBuyer->execute();
            $updateBuyer->close();

            $flashMessage = 'Buyer details updated successfully.';
            $flashType = 'success';
        } elseif ($action === 'update_remarks') {
            $buyerId = (int) ($_POST['buyer_id'] ?? 0);
            $remarks = trim((string) ($_POST['remarks'] ?? ''));

            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid buyer selected.');
            }
            if (!in_array($remarks, $allowedRemarks, true)) {
                throw new RuntimeException('Invalid remarks value selected.');
            }

            $remarksValue = $remarks !== '' ? $remarks : null;
            $updateRemarks = $conn->prepare('UPDATE buyers SET remarks = ?, updated_at = NOW() WHERE id = ?');
            if (!$updateRemarks) {
                throw new RuntimeException('Unable to update buyer remarks.');
            }

            $updateRemarks->bind_param('si', $remarksValue, $buyerId);
            $updateRemarks->execute();
            $updateRemarks->close();

            $flashMessage = 'Buyer remarks updated successfully.';
            $flashType = 'success';
        } elseif ($action === 'send_email') {
            $buyerId = (int) ($_POST['buyer_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid buyer selected.');
            }

            $buyerStmt = $conn->prepare("SELECT b.id, b.full_name, b.email, b.status, b.chapter, b.buyer_type,
                    GROUP_CONCAT(ta.ticket_number ORDER BY ta.ticket_number SEPARATOR ', ') AS tickets
                FROM buyers b
                LEFT JOIN ticket_assignments ta ON ta.buyer_id = b.id
                WHERE b.id = ?
                GROUP BY b.id");
            if (!$buyerStmt) {
                throw new RuntimeException('Unable to read buyer details.');
            }

            $buyerStmt->bind_param('i', $buyerId);
            $buyerStmt->execute();
            $buyer = $buyerStmt->get_result()->fetch_assoc();
            $buyerStmt->close();

            if (!$buyer) {
                throw new RuntimeException('Buyer not found.');
            }
            if (empty($buyer['tickets'])) {
                throw new RuntimeException('No assigned tickets found for this buyer.');
            }

            $ticketNumbers = array_map('trim', explode(',', (string) $buyer['tickets']));
            $ticketNumbers = array_values(array_filter($ticketNumbers, static function (string $ticketNumber): bool {
                return $ticketNumber !== '';
            }));

            $sendResult = ticket_send_buyer_assignment_email($conn, $appConfig, $buyer, $ticketNumbers);
            $deliveryMethod = (string) ($sendResult['delivery_method'] ?? 'Gmail API');

            $updateStatus = $conn->prepare("UPDATE buyers
                SET status = ?, email_sent_at = NOW(), updated_at = NOW()
                WHERE id = ?");
            if (!$updateStatus) {
                throw new RuntimeException('Unable to update buyer status after email send.');
            }

            $statusAfterEmail = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
            $updateStatus->bind_param('si', $statusAfterEmail, $buyerId);
            $updateStatus->execute();
            $updateStatus->close();

            $flashMessage = 'Email sent successfully via ' . $deliveryMethod . '.';
            $flashType = 'success';
        }
    } catch (Throwable $e) {
        $flashMessage = $e->getMessage();
        $flashType = 'danger';
    }
}

$search = trim($_GET['search'] ?? '');
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$allowedPerPage = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$whereSql = '';
$term = '';
if ($search !== '') {
    $whereSql = ' WHERE b.full_name LIKE ? OR b.email LIKE ? OR b.chapter LIKE ?';
    $term = '%' . $search . '%';
}

$countSql = 'SELECT COUNT(*) AS total FROM buyers b' . $whereSql;
$countStmt = $conn->prepare($countSql);
$totalBuyers = 0;
if ($countStmt) {
    if ($search !== '') {
        $countStmt->bind_param('sss', $term, $term, $term);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    $totalBuyers = (int) ($countRow['total'] ?? 0);
    $countStmt->close();
}

$totalPages = max(1, (int) ceil($totalBuyers / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;

$listSql = "SELECT b.id, b.buyer_type, b.full_name, b.email, b.contact, b.chapter, b.status, b.remarks, b.email_sent_at,
        SUM(CASE WHEN LOWER(COALESCE(t.status, 'pending')) = 'activated' THEN 1 ELSE 0 END) AS activated_tickets,
        COUNT(ta.id) AS total_tickets,
        MIN(ta.ticket_number) AS first_ticket,
        MAX(ta.ticket_number) AS last_ticket,
        GROUP_CONCAT(ta.ticket_number ORDER BY ta.ticket_number SEPARATOR ', ') AS tickets
    FROM buyers b
    LEFT JOIN ticket_assignments ta ON ta.buyer_id = b.id
    LEFT JOIN tickets t ON t.ticket_number = ta.ticket_number" .
    $whereSql .
    ' GROUP BY b.id ORDER BY b.created_at DESC LIMIT ? OFFSET ?';

$listStmt = $conn->prepare($listSql);
$buyers = [];
if ($listStmt) {
    if ($search !== '') {
        $listStmt->bind_param('sssii', $term, $term, $term, $perPage, $offset);
    } else {
        $listStmt->bind_param('ii', $perPage, $offset);
    }

    $listStmt->execute();
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $buyers[] = $row;
    }
    $listStmt->close();
}

$paginationBaseParams = [];
if ($search !== '') {
    $paginationBaseParams['search'] = $search;
}
$paginationBaseParams['per_page'] = $perPage;

$startRecord = $totalBuyers > 0 ? ($offset + 1) : 0;
$endRecord = $totalBuyers > 0 ? min($offset + count($buyers), $totalBuyers) : 0;

$pageLinks = [];
if ($totalPages <= 7) {
    for ($p = 1; $p <= $totalPages; $p++) {
        $pageLinks[] = $p;
    }
} else {
    $startWindow = max(1, $currentPage - 2);
    $endWindow = min($totalPages, $currentPage + 2);

    if ($startWindow > 1) {
        $pageLinks[] = 1;
        if ($startWindow > 2) {
            $pageLinks[] = -1;
        }
    }

    for ($p = $startWindow; $p <= $endWindow; $p++) {
        $pageLinks[] = $p;
    }

    if ($endWindow < $totalPages) {
        if ($endWindow < $totalPages - 1) {
            $pageLinks[] = -1;
        }
        $pageLinks[] = $totalPages;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }
        :root {
            --primary: #283555;
            --secondary: #3a4a6f;
            --accent: #ff6b35;
            --light: #f4f6f9;
            --dark: #2c3e50;
            --white: #ffffff;
            --gray: #6c757d;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --border-color: #e8eef5;
            --transition: 0.3s ease;
            --transition-speed: 0.3s;
        }
        body {
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            color: var(--dark);
        }
        .container {
            max-width: 1200px;
        }
        .btn {
            padding: 10px 24px;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
            border: none;
            font-family: 'Poppins', sans-serif;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }
        .btn-outline-primary, .btn-outline-secondary {
            transition: all var(--transition);
        }
        .btn-outline-primary:hover, .btn-outline-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 53, 85, 0.2);
        }
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #e67e22 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
        }
        .card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(40, 53, 85, 0.12);
            transition: all var(--transition);
            overflow: hidden;
        }
        .card:hover {
            box-shadow: 0 12px 40px rgba(40, 53, 85, 0.15);
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            font-weight: 600;
            border: none;
            padding: 20px;
        }
        .card-body {
            padding: 24px;
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            font-weight: 700;
            border: none;
            padding: 16px;
        }
        .table td {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            color: var(--dark);
        }
        .table tbody tr:hover {
            background: var(--light);
        }
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-family: 'Poppins', sans-serif;
            transition: all var(--transition);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.1);
        }
        .alert {
            border: none;
            border-radius: 8px;
            padding: 16px 20px;
            animation: slideInDown 0.4s ease-out;
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
        h2 {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 24px;
            font-size: 2rem;
        }
        h5.card-title {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .badge {
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .remarks-pill {
            display: inline-flex;
            padding: 0.25rem 0.62rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.82rem;
            background: #e5e7eb;
            color: #374151;
        }
        .remarks-pill.paid,
        .remarks-pill.fully-paid {
            background: #dcfce7;
            color: #166534;
        }
        .remarks-pill.partially-paid {
            background: #fef9c3;
            color: #854d0e;
        }
        .remarks-pill.pending,
        .remarks-pill.not-paid {
            background: #fee2e2;
            color: #991b1b;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        aside.sidebar {
            width: 250px;
            height: 100vh;
            top: 0;
            z-index: 50;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            transition: width 0.3s ease;
            color: white;
            padding: 22px 20px 30px 20px;
            position: fixed;
            bottom: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
        }

        aside.sidebar .sidebar-header {
            text-align: center;
            align-items: center;
            margin-bottom: 10px;
            display: flex;
        }

        aside.sidebar .sidebar-header h1 {
            font-size: 16px;
            color: var(--white);
            text-transform: uppercase;
            margin: 0;
        }

        aside.sidebar nav ul {
            list-style: none;
            padding: 0;
            margin-top: 10px;
        }

        aside.sidebar nav ul li {
            margin: 10px 0;
        }

        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            color: var(--light);
            text-decoration: none;
            font-size: 16px;
            transition: background var(--transition-speed) ease, padding-left var(--transition-speed) ease;
            padding: 8px 12px;
            border-radius: 5px;
        }

        aside.sidebar nav ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background: rgba(255, 107, 53, 0.2);
            padding-left: 15px;
            border-left: 3px solid var(--accent);
        }

        main.main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
        }

        @media (max-width: 768px) {
            aside.sidebar {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
            }

            main.main-content {
                margin-left: 0;
                padding: 10px;
            }

            h2 {
                font-size: 1.6rem;
            }
            .btn {
                padding: 8px 16px;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/participant_list.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Buyer List</h2>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>">
            <?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>" placeholder="Search name, email, chapter">
                </div>
                <div class="col-md-2">
                    <select name="per_page" class="form-select" aria-label="Rows per page">
                        <?php foreach ($allowedPerPage as $pageSize): ?>
                            <option value="<?php echo (int) $pageSize; ?>" <?php echo $perPage === $pageSize ? 'selected' : ''; ?>>
                                <?php echo (int) $pageSize; ?> / page
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary">Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="text-muted small">
            Showing <?php echo (int) $startRecord; ?>-<?php echo (int) $endRecord; ?> of <?php echo (int) $totalBuyers; ?> buyers
        </div>
        <div class="text-muted small">Page <?php echo (int) $currentPage; ?> of <?php echo (int) $totalPages; ?></div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Contact</th>
                    <th>Chapter</th>
                    <th>Remarks</th>
                    <th>Status</th>
                    <th>Activation Progress</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($buyers)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No buyers found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($buyers as $buyer): ?>
                        <?php $activatedTickets = (int) ($buyer['activated_tickets'] ?? 0); ?>
                        <?php $totalTickets = (int) ($buyer['total_tickets'] ?? 0); ?>
                        <?php
                        $buyerRemarksValue = trim((string) ($buyer['remarks'] ?? ''));
                        $buyerRemarksClass = strtolower(str_replace(' ', '-', $buyerRemarksValue !== '' ? $buyerRemarksValue : 'pending'));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($buyer['full_name'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($buyer['email'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($buyer['contact'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($buyer['chapter'], ENT_QUOTES); ?></td>
                            <td>
                                <span class="remarks-pill <?php echo htmlspecialchars($buyerRemarksClass, ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($buyerRemarksValue !== '' ? $buyerRemarksValue : 'Pending', ENT_QUOTES); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(buyerStatusLabel($buyer['status']), ENT_QUOTES); ?></td>
                            <td><?php echo $activatedTickets; ?> / <?php echo $totalTickets; ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#buyerModal<?php echo (int) $buyer['id']; ?>">
                                        View Details
                                    </button>
                                    <?php if ($canManageBuyers): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#buyerRemarksModal<?php echo (int) $buyer['id']; ?>">
                                            Edit Remarks
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Buyer list pagination">
            <ul class="pagination justify-content-center mb-0">
                <?php
                $prevParams = $paginationBaseParams;
                $prevParams['page'] = max(1, $currentPage - 1);
                ?>
                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prevParams), ENT_QUOTES); ?>">Previous</a>
                </li>

                <?php foreach ($pageLinks as $pageNumber): ?>
                    <?php if ($pageNumber === -1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <?php
                        $pageParams = $paginationBaseParams;
                        $pageParams['page'] = $pageNumber;
                        ?>
                        <li class="page-item <?php echo $pageNumber === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($pageParams), ENT_QUOTES); ?>"><?php echo (int) $pageNumber; ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php
                $nextParams = $paginationBaseParams;
                $nextParams['page'] = min($totalPages, $currentPage + 1);
                ?>
                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($nextParams), ENT_QUOTES); ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if (!empty($buyers)): ?>
        <?php foreach ($buyers as $buyer): ?>
            <?php
            $fieldReadonlyAttr = $canManageBuyers ? '' : 'readonly';
            $fieldDisabledAttr = $canManageBuyers ? '' : 'disabled';
            ?>
            <div class="modal fade" id="buyerRemarksModal<?php echo (int) $buyer['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Remarks: <?php echo htmlspecialchars($buyer['full_name'], ENT_QUOTES); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_remarks">
                                <input type="hidden" name="buyer_id" value="<?php echo (int) $buyer['id']; ?>">

                                <label class="form-label">Remarks</label>
                                <?php $currentRemarks = trim((string) ($buyer['remarks'] ?? '')); ?>
                                <select name="remarks" class="form-select" required>
                                    <?php foreach ($allowedRemarks as $remarksOption): ?>
                                        <option value="<?php echo htmlspecialchars($remarksOption, ENT_QUOTES); ?>" <?php echo $currentRemarks === $remarksOption ? 'selected' : ''; ?>>
                                            <?php echo $remarksOption === '' ? 'Select Remarks' : htmlspecialchars($remarksOption, ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Use a fixed option to avoid typographical errors.</div>

                                <div class="d-flex justify-content-end mt-3">
                                    <button type="submit" class="btn btn-primary">Save Remarks</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="buyerModal<?php echo (int) $buyer['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Buyer Details: <?php echo htmlspecialchars($buyer['full_name'], ENT_QUOTES); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" class="row g-3 mb-3">
                                <input type="hidden" name="action" value="update_buyer">
                                <input type="hidden" name="buyer_id" value="<?php echo (int) $buyer['id']; ?>">

                                <div class="col-md-4">
                                    <label class="form-label">Buyer Type</label>
                                    <select name="buyer_type" class="form-select" required <?php echo $fieldDisabledAttr; ?>>
                                        <option value="individual" <?php echo strtolower((string) $buyer['buyer_type']) === 'individual' ? 'selected' : ''; ?>>Individual</option>
                                        <option value="chapter" <?php echo strtolower((string) $buyer['buyer_type']) === 'chapter' ? 'selected' : ''; ?>>Chapter</option>
                                        <option value="bulk" <?php echo strtolower((string) $buyer['buyer_type']) === 'bulk' ? 'selected' : ''; ?>>Bulk</option>
                                    </select>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($buyer['full_name'], ENT_QUOTES); ?>" required <?php echo $fieldReadonlyAttr; ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($buyer['email'], ENT_QUOTES); ?>" required <?php echo $fieldReadonlyAttr; ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Contact</label>
                                    <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($buyer['contact'], ENT_QUOTES); ?>" required <?php echo $fieldReadonlyAttr; ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Chapter</label>
                                    <input type="text" name="chapter" class="form-control" value="<?php echo htmlspecialchars($buyer['chapter'], ENT_QUOTES); ?>" required <?php echo $fieldReadonlyAttr; ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <input type="text" name="status" class="form-control" value="<?php echo htmlspecialchars($buyer['status'], ENT_QUOTES); ?>" required <?php echo $fieldReadonlyAttr; ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Email Sent At</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars((string) ($buyer['email_sent_at'] ?? ''), ENT_QUOTES); ?>" readonly>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Total Tickets</label>
                                    <input type="text" class="form-control" value="<?php echo (int) ($buyer['total_tickets'] ?? 0); ?>" readonly>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Activation Progress</label>
                                    <input type="text" class="form-control" value="<?php echo (int) ($buyer['activated_tickets'] ?? 0); ?> / <?php echo (int) ($buyer['total_tickets'] ?? 0); ?>" readonly>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Assigned Tickets</label>
                                    <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars((string) ($buyer['tickets'] ?? '-'), ENT_QUOTES); ?></textarea>
                                </div>

                                <div class="col-12 d-flex justify-content-end">
                                    <?php if ($canManageBuyers): ?>
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    <?php endif; ?>
                                </div>
                            </form>

                            <?php if ($canResendEmail): ?>
                                <form method="POST" class="d-flex justify-content-end">
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="buyer_id" value="<?php echo (int) $buyer['id']; ?>">
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-warning">Resend Email</button>
                                        <div class="small text-muted mt-2">Resending issues a fresh secure activation link and invalidates the previous one.</div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



