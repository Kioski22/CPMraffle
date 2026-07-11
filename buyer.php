<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

include 'db.php';
require_once 'buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

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
            throw new RuntimeException('cURL extension is required for Gmail API HTTP send.');
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
            throw new RuntimeException('cURL extension is required for Gmail API HTTP send.');
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

if (!function_exists('send_buyer_assignment_email')) {
    function send_buyer_assignment_email(mysqli $conn, array $appConfig, array $buyer, array $ticketNumbers): array
    {
        return ticket_send_buyer_assignment_email($conn, $appConfig, $buyer, $ticketNumbers);
    }
}


if (!function_exists('buyer_fetch_available_pending_tickets')) {
    function buyer_fetch_available_pending_tickets(mysqli $conn, int $quantity): array
    {
        $ticketNumbers = [];

        $availableStmt = $conn->prepare("SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE ta.ticket_number IS NULL
              AND LOWER(t.status) = 'pending'
            ORDER BY t.ticket_number ASC
            LIMIT ? FOR UPDATE");

        if (!$availableStmt) {
            throw new RuntimeException('Unable to fetch available tickets.');
        }

        $availableStmt->bind_param('i', $quantity);
        $availableStmt->execute();
        $availableResult = $availableStmt->get_result();

        while ($row = $availableResult->fetch_assoc()) {
            $ticketNumbers[] = $row['ticket_number'];
        }
        $availableStmt->close();

        return $ticketNumbers;
    }
}

if (!function_exists('buyer_validate_ticket_numbers_available')) {
    function buyer_validate_ticket_numbers_available(mysqli $conn, array $ticketNumbers): void
    {
        if (empty($ticketNumbers)) {
            throw new RuntimeException('No tickets were selected for assignment.');
        }

        $placeholders = implode(',', array_fill(0, count($ticketNumbers), '?'));
        $availabilitySql = "SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number IN ($placeholders)
              AND ta.ticket_number IS NULL
              AND LOWER(t.status) = 'pending'
            FOR UPDATE";

        $availabilityStmt = $conn->prepare($availabilitySql);
        if (!$availabilityStmt) {
            throw new RuntimeException('Unable to validate ticket availability.');
        }

        $types = str_repeat('s', count($ticketNumbers));
        $bindValues = [$types];
        foreach ($ticketNumbers as $key => $value) {
            $bindValues[] = &$ticketNumbers[$key];
        }
        call_user_func_array([$availabilityStmt, 'bind_param'], $bindValues);

        $availabilityStmt->execute();
        $availabilityResult = $availabilityStmt->get_result();
        $availableTickets = [];
        while ($row = $availabilityResult->fetch_assoc()) {
            $availableTickets[] = $row['ticket_number'];
        }
        $availabilityStmt->close();

        if (count($availableTickets) !== count($ticketNumbers)) {
            $missingTickets = array_values(array_diff($ticketNumbers, $availableTickets));
            $preview = implode(', ', array_slice($missingTickets, 0, 5));
            throw new RuntimeException('Some tickets are already assigned or unavailable: ' . $preview);
        }
    }
}

ensureBuyerSchema($conn);

$message = '';
$messageType = 'success';
$ticketPrefix = getTicketNumberPrefix($conn);

// Default form values for initial GET render.
$buyerType = 'individual';
$fullName = '';
$email = '';
$contact = '';
$chapter = '';
$assignmentMode = 'quantity';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $buyerType = strtolower(trim($_POST['buyer_type'] ?? ''));
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $chapter = trim($_POST['chapter'] ?? '');
    $assignmentMode = strtolower(trim($_POST['assignment_mode'] ?? ''));

    try {
        if (!in_array($buyerType, ['individual'], true)) {
            throw new RuntimeException('Invalid buyer type.');
        }
        if (!in_array($assignmentMode, ['range', 'quantity'], true)) {
            throw new RuntimeException('Invalid assignment mode.');
        }

        if ($fullName === '' || $email === '' || $contact === '' || $chapter === '') {
            throw new RuntimeException('All buyer fields are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $conn->begin_transaction();

        $insertBuyer = $conn->prepare('INSERT INTO buyers (buyer_type, full_name, email, contact, chapter, status) VALUES (?, ?, ?, ?, ?, "pending")');
        if (!$insertBuyer) {
            throw new RuntimeException('Unable to create buyer.');
        }
        $insertBuyer->bind_param('sssss', $buyerType, $fullName, $email, $contact, $chapter);
        if (!$insertBuyer->execute()) {
            throw new RuntimeException('Unable to create buyer.');
        }

        $buyerId = (int) $insertBuyer->insert_id;
        $insertBuyer->close();

        $ticketNumbers = [];

        if ($assignmentMode === 'range') {
            $rangeStart = (int) ($_POST['range_start'] ?? 0);
            $rangeEnd = (int) ($_POST['range_end'] ?? 0);

            if ($rangeStart <= 0 || $rangeEnd <= 0 || $rangeEnd < $rangeStart) {
                throw new RuntimeException('Invalid range values.');
            }

            if (($rangeEnd - $rangeStart) > 1000) {
                throw new RuntimeException('Range is too large. Please split the assignment into smaller batches.');
            }

            for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
                $ticketNumbers[] = formatTicketNumber($ticketPrefix, $i);
            }
        } else {
            $quantity = (int) ($_POST['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > 1000) {
                throw new RuntimeException('Quantity must be between 1 and 1000.');
            }

            $ticketNumbers = buyer_fetch_available_pending_tickets($conn, $quantity);
            if (count($ticketNumbers) !== $quantity) {
                throw new RuntimeException('Not enough available tickets in the pool for the requested quantity.');
            }
        }

        buyer_validate_ticket_numbers_available($conn, $ticketNumbers);

        $adminId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
        $insertAssignment = $conn->prepare('INSERT INTO ticket_assignments (buyer_id, ticket_number, assignment_mode, assigned_by_admin_id, assignment_status) VALUES (?, ?, ?, ?, "assigned")');
        if (!$insertAssignment) {
            throw new RuntimeException('Unable to store ticket assignments.');
        }

        foreach ($ticketNumbers as $ticketNumber) {
            $insertAssignment->bind_param('issi', $buyerId, $ticketNumber, $assignmentMode, $adminId);
            if (!$insertAssignment->execute()) {
                throw new RuntimeException('Failed to assign ticket: ' . $ticketNumber);
            }
        }
        $insertAssignment->close();

        $conn->commit();

        $deliveryMethod = '';
        try {
            $sendResult = send_buyer_assignment_email($conn, $appConfig, [
                'id' => $buyerId,
                'full_name' => $fullName,
                'email' => $email,
                'chapter' => $chapter,
                'buyer_type' => $buyerType,
            ], $ticketNumbers);
            $deliveryMethod = (string) ($sendResult['delivery_method'] ?? 'Gmail API');

            $statusAfterEmail = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
            $markSent = $conn->prepare("UPDATE buyers
                SET status = ?, email_sent_at = NOW(), updated_at = NOW()
                WHERE id = ?");
            if ($markSent) {
                $markSent->bind_param('si', $statusAfterEmail, $buyerId);
                $markSent->execute();
                $markSent->close();
            }
        } catch (Throwable $mailException) {
            $message = 'Buyer created and ' . count($ticketNumbers) . ' ticket(s) assigned successfully, but initial email failed: ' . $mailException->getMessage();
            $messageType = 'warning';
        }

        if ($message === '') {
            $message = 'Buyer created and ' . count($ticketNumbers) . ' ticket(s) assigned successfully. Initial email sent via ' . $deliveryMethod . '.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        @$conn->rollback();
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$availableCountQuery = $conn->query("SELECT COUNT(*) AS total
    FROM tickets t
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    WHERE ta.ticket_number IS NULL
      AND LOWER(t.status) = 'pending'");
$availableCount = 0;
if ($availableCountQuery instanceof mysqli_result) {
    $availableCount = (int) ($availableCountQuery->fetch_assoc()['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Assignment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
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
            font-family: 'Poppins', sans-serif;
            transition: all var(--transition);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border: none;
            padding: 10px 24px;
            font-weight: 600;
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
        .form-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            transition: all var(--transition);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.1);
        }
        .ticket-assignment-panel {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: linear-gradient(180deg, #fbfcff 0%, #f6f9fd 100%);
            padding: 18px;
        }
        .ticket-assignment-title {
            margin-bottom: 4px;
        }
        .ticket-assignment-help {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 14px;
        }
        .assignment-mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
        }
        .assignment-mode-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .assignment-mode-card {
            border: 2px solid #dbe4ef;
            border-radius: 12px;
            background: #ffffff;
            padding: 14px;
            cursor: pointer;
            transition: all var(--transition);
            display: block;
            height: 100%;
        }
        .assignment-mode-card:hover {
            border-color: #9cb2ce;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(40, 53, 85, 0.1);
        }
        .assignment-mode-input:checked + .assignment-mode-card {
            border-color: var(--primary);
            background: #eef3fb;
            box-shadow: 0 10px 20px rgba(40, 53, 85, 0.12);
        }
        .assignment-mode-heading {
            color: var(--primary);
            font-weight: 700;
            margin: 0 0 4px;
            font-size: 0.97rem;
        }
        .assignment-mode-desc {
            color: var(--gray);
            margin: 0;
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .ticket-assignment-subhelp {
            color: var(--gray);
            font-size: 0.85rem;
            margin-top: 6px;
        }
        .alert {
            border: none;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
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
        .d-flex {
            flex-wrap: wrap;
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
    <?php render_dashboard_sidebar('/CPMraffle/buyer.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Buyer Assignment</h2>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <strong>Available tickets in pool:</strong> <?php echo number_format($availableCount); ?><br>
            <small class="text-muted">Ticket prefix detected: <?php echo htmlspecialchars($ticketPrefix, ENT_QUOTES); ?></small>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" id="buyer-form">
                <div class="row g-3">
                    <input type="hidden" name="buyer_type" value="individual">

                    <div class="col-md-5 manual-field-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control manual-field" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-4 manual-field-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control manual-field" value="<?php echo htmlspecialchars($email, ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-4 manual-field-group">
                        <label class="form-label">Contact</label>
                        <input type="text" name="contact" class="form-control manual-field" value="<?php echo htmlspecialchars($contact, ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-4 manual-field-group">
                        <label class="form-label">Chapter</label>
                        <input type="text" name="chapter" class="form-control manual-field" value="<?php echo htmlspecialchars($chapter, ENT_QUOTES); ?>" required>
                    </div>
                    <div id="assignment_controls" class="col-12">
                        <div class="ticket-assignment-panel">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label ticket-assignment-title">Ticket Assignment</label>
                                    <div class="ticket-assignment-help">Choose how you want tickets assigned for this buyer.</div>
                                </div>
                                <div class="col-12">
                                    <div class="assignment-mode-grid" role="radiogroup" aria-label="Assignment mode">
                                        <label class="assignment-mode-option">
                                            <input type="radio" name="assignment_mode" class="assignment-mode-input" value="quantity" <?php echo $assignmentMode === 'quantity' ? 'checked' : ''; ?> required>
                                            <span class="assignment-mode-card">
                                                <span class="assignment-mode-heading">Auto-Assign (Quantity)</span>
                                                <span class="assignment-mode-desc">Automatically assign the next available tickets from the pool.</span>
                                            </span>
                                        </label>
                                        <label class="assignment-mode-option">
                                            <input type="radio" name="assignment_mode" class="assignment-mode-input" value="range" <?php echo $assignmentMode === 'range' ? 'checked' : ''; ?> required>
                                            <span class="assignment-mode-card">
                                                <span class="assignment-mode-heading">Manual Sequence (Range)</span>
                                                <span class="assignment-mode-desc">Manually specify the starting and ending ticket numbers.</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <div id="range_fields" class="col-12">
                                    <label class="form-label mb-0">Manual Sequence Details</label>
                                    <div class="row g-3 mt-0">
                                        <div class="col-md-3">
                                            <label class="form-label">Start Ticket Number</label>
                                            <input type="number" min="1" name="range_start" id="range_start" class="form-control" placeholder="1" value="<?php echo htmlspecialchars((string) ($_POST['range_start'] ?? ''), ENT_QUOTES); ?>">
                                            <div class="ticket-assignment-subhelp">First ticket number to include in this assignment.</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">End Ticket Number</label>
                                            <input type="number" min="1" name="range_end" id="range_end" class="form-control" placeholder="10" value="<?php echo htmlspecialchars((string) ($_POST['range_end'] ?? ''), ENT_QUOTES); ?>">
                                            <div class="ticket-assignment-subhelp">Last ticket number to include in this assignment.</div>
                                        </div>
                                        <div class="col-12">
                                            <div id="range_validation_indicator" class="mt-1"></div>
                                        </div>
                                    </div>
                                </div>

                                <div id="quantity_field" class="col-12" style="display:none;">
                                    <label class="form-label mb-0">Auto-Assign Details</label>
                                    <div class="row g-3 mt-0">
                                        <div class="col-md-3">
                                            <label class="form-label">Quantity</label>
                                            <input type="number" min="1" name="quantity" id="quantity" class="form-control" placeholder="25" value="<?php echo htmlspecialchars((string) ($_POST['quantity'] ?? ''), ENT_QUOTES); ?>">
                                            <div class="ticket-assignment-subhelp">The system will automatically find this many pending tickets.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary" id="primary_submit_button">Create Buyer and Assign Tickets</button>
                </div>
            </form>
        </div>
    </div>

</div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const assignmentModes = Array.from(document.querySelectorAll('input[name="assignment_mode"]'));
    const rangeFields = document.getElementById('range_fields');
    const quantityField = document.getElementById('quantity_field');
    const rangeStart = document.getElementById('range_start');
    const rangeEnd = document.getElementById('range_end');
    const quantity = document.getElementById('quantity');

    function getSelectedAssignmentMode() {
        const selected = assignmentModes.find((input) => input.checked);
        return selected ? selected.value : 'quantity';
    }

    function toggleAssignmentFields() {
        if (getSelectedAssignmentMode() === 'quantity') {
            rangeFields.style.display = 'none';
            quantityField.style.display = '';
            rangeStart.required = false;
            rangeEnd.required = false;
            quantity.required = true;
        } else {
            rangeFields.style.display = '';
            quantityField.style.display = 'none';
            rangeStart.required = true;
            rangeEnd.required = true;
            quantity.required = false;
        }
    }

    assignmentModes.forEach((input) => {
        input.addEventListener('change', toggleAssignmentFields);
    });
    toggleAssignmentFields();

    // Range availability indicator
    const rangeIndicator = document.getElementById('range_validation_indicator');
    const primarySubmitBtn = document.getElementById('primary_submit_button');
    let rangeDebounceTimer = null;
    let rangeInvalid = false;

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function validateBuyerRange() {
        if (getSelectedAssignmentMode() !== 'range') {
            return;
        }
        const start = rangeStart.value.trim();
        const end = rangeEnd.value.trim();

        if (start === '' || end === '') {
            rangeIndicator.innerHTML = '';
            rangeInvalid = false;
            primarySubmitBtn.disabled = false;
            return;
        }

        rangeIndicator.innerHTML = '<div class="alert alert-secondary py-1 px-3 mb-0">Checking availability&hellip;</div>';
        primarySubmitBtn.disabled = true;
        rangeInvalid = true;

        const url = '/CPMraffle/check_ticket_range_api.php?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.available) {
                    rangeIndicator.innerHTML = '<div class="alert alert-success py-1 px-3 mb-0"><i class="fa fa-check-circle me-1"></i>' + escapeHtml(data.message) + '</div>';
                    rangeInvalid = false;
                    primarySubmitBtn.disabled = false;
                } else {
                    rangeIndicator.innerHTML = '<div class="alert alert-danger py-1 px-3 mb-0"><i class="fa fa-exclamation-circle me-1"></i>' + escapeHtml(data.message) + '</div>';
                    rangeInvalid = true;
                    primarySubmitBtn.disabled = true;
                }
            })
            .catch(function () {
                rangeIndicator.innerHTML = '';
                rangeInvalid = false;
                primarySubmitBtn.disabled = false;
            });
    }

    function debouncedRangeValidate() {
        clearTimeout(rangeDebounceTimer);
        rangeDebounceTimer = setTimeout(validateBuyerRange, 500);
    }

    rangeStart.addEventListener('input', debouncedRangeValidate);
    rangeEnd.addEventListener('input', debouncedRangeValidate);

    // Clear indicator when switching away from range mode
    assignmentModes.forEach((input) => {
        input.addEventListener('change', function () {
            if (getSelectedAssignmentMode() !== 'range') {
                rangeIndicator.innerHTML = '';
                rangeInvalid = false;
                primarySubmitBtn.disabled = false;
            } else {
                validateBuyerRange();
            }
        });
    });
</script>
</body>
</html>



