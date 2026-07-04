<?php
session_start();

require 'db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/buyer_activation_helper.php';
require_once __DIR__ . '/ticket_activation_service.php';

ensureBuyerSchema($conn);
$activationClosed = ticket_activation_is_closed();
$accessToken = trim((string) ($_GET['access'] ?? $_POST['access'] ?? ''));
$buyer = $accessToken !== '' ? ticket_find_buyer_by_activation_access($conn, $accessToken) : null;
$tickets = $buyer ? ticket_fetch_buyer_assigned_tickets($conn, (int) $buyer['id']) : [];
$flashMessage = '';
$flashType = 'success';

if (!isset($_SESSION['buyer_activation_csrf'])) {
    $_SESSION['buyer_activation_csrf'] = bin2hex(random_bytes(32));
}

if ($buyer && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['buyer_activation_csrf'], $csrfToken)) {
            throw new RuntimeException('Invalid activation request. Please refresh and try again.');
        }
        if ($activationClosed) {
            throw new RuntimeException(ticket_activation_closed_message());
        }
        if (empty($_POST['accept_terms'])) {
            throw new RuntimeException('You must accept the terms and confirm your details.');
        }

        // Activate all tickets assigned to this buyer
        $allTicketNumbers = array_map(static function (array $ticket): string {
            return (string) ($ticket['ticket_number'] ?? '');
        }, $tickets);

        $result = ticket_activate_tickets_for_buyer(
            $conn,
            $appConfig,
            (int) $buyer['id'],
            $allTicketNumbers,
            trim((string) ($_POST['full_name'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            trim((string) ($_POST['contact_number'] ?? ''))
        );

        ticket_mark_buyer_activation_access_used($conn, (int) $buyer['id']);
        $buyer = ticket_find_buyer_by_activation_access($conn, $accessToken);
        $tickets = $buyer ? ticket_fetch_buyer_assigned_tickets($conn, (int) $buyer['id']) : [];

        $flashMessage = 'Ticket activation completed for ' . implode(', ', $result['activated_tickets']) . '.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

if ($buyer) {
    ticket_mark_buyer_activation_access_used($conn, (int) $buyer['id']);
}

$ticketNumbers = array_map(static function (array $ticket): string {
    return (string) ($ticket['ticket_number'] ?? '');
}, $tickets);
$ticketRangeSummary = ticket_format_number_ranges(array_filter($ticketNumbers));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Activation Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f4f6f9 0%, #ffffff 100%);
            color: #2c3e50;
        }
        .portal-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 16px 48px;
        }
        .hero, .card {
            background: #ffffff;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            box-shadow: 0 18px 60px rgba(40, 53, 85, 0.12);
        }
        .hero {
            padding: 28px;
            margin-bottom: 20px;
        }
        .hero h1 {
            margin: 0 0 10px;
            color: #283555;
            font-size: 1.8rem;
            font-weight: 700;
        }
        p {
            margin: 0 0 14px;
            line-height: 1.6;
        }
        .alert {
            border-radius: 12px;
            padding: 14px 16px;
            margin: 18px 0;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .table thead th {
            background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%);
            color: #ffffff;
            border: none;
        }
        .status-pill {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-pill.pending { background: #fff3cd; color: #856404; }
        .status-pill.activated { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
<div class="portal-shell">
    <div class="hero">
        <h1>Ticket Activation Portal</h1>
        <?php if (!$buyer): ?>
            <p class="mb-0">This activation link is invalid or has expired.</p>
        <?php else: ?>
            <p class="mb-2">Buyer: <strong><?php echo htmlspecialchars((string) $buyer['full_name'], ENT_QUOTES); ?></strong></p>
            <p class="mb-2">Assigned Tickets: <strong><?php echo htmlspecialchars($ticketRangeSummary !== '' ? $ticketRangeSummary : implode(', ', $ticketNumbers), ENT_QUOTES); ?></strong></p>
            <p class="mb-0">Use this page to activate the ticket(s) assigned to you. Already activated tickets are shown but cannot be selected again.</p>
        <?php endif; ?>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if ($buyer): ?>
        <?php if ($activationClosed): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars(ticket_activation_closed_message(), ENT_QUOTES); ?></div>
        <?php endif; ?>

        <div class="card p-4 mb-4">
            <form method="POST">
                <input type="hidden" name="access" value="<?php echo htmlspecialchars($accessToken, ENT_QUOTES); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['buyer_activation_csrf'], ENT_QUOTES); ?>">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" maxlength="120" value="<?php echo htmlspecialchars((string) ($buyer['full_name'] ?? ''), ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="150" value="<?php echo htmlspecialchars((string) ($buyer['email'] ?? ''), ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" maxlength="30" value="<?php echo htmlspecialchars((string) ($buyer['contact'] ?? ''), ENT_QUOTES); ?>" required>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Ticket Number</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <?php $isActivated = strtolower((string) ($ticket['ticket_status'] ?? 'pending')) === 'activated'; ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $ticket['ticket_number'], ENT_QUOTES); ?></td>
                                <td><span class="status-pill <?php echo $isActivated ? 'activated' : 'pending'; ?>"><?php echo htmlspecialchars($isActivated ? 'Activated' : 'Pending', ENT_QUOTES); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="accept_terms" id="accept_terms" value="1" required>
                    <label class="form-check-label" for="accept_terms">
                        I confirm that the above details are true and correct, I accept the designated tickets, and I understand that grand prizes are subject to tax.
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" <?php echo $activationClosed ? 'disabled' : ''; ?>>Acknowledge and Confirm</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card p-4">
            <div class="alert alert-warning mb-0">This activation link is invalid, expired, or no longer available. Please use the latest link from your ticket assignment email.</div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
