<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';

ensureBuyerSchema($conn);

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

$ticketInput = trim((string) ($_POST['ticket_number'] ?? ''));
$searchedTicket = '';
$resultState = 'idle';
$resultTitle = 'Ready To Draw';
$resultMessage = 'Enter a ticket number to reveal whether it is eligible for the raffle draw.';
$winnerData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchedTicket = strtoupper(trim($ticketInput));

    if ($searchedTicket === '') {
        $resultState = 'error';
        $resultTitle = 'Ticket Required';
        $resultMessage = 'Please enter a ticket number.';
    } else {
        $existsStmt = $conn->prepare('SELECT
                EXISTS(SELECT 1 FROM tickets WHERE ticket_number = ?) AS in_tickets,
                EXISTS(SELECT 1 FROM generated_tickets WHERE ticket_number = ?) AS in_generated');

        if (!$existsStmt) {
            $resultState = 'error';
            $resultTitle = 'Lookup Error';
            $resultMessage = 'Unable to validate ticket records at the moment.';
        } else {
            $existsStmt->bind_param('ss', $searchedTicket, $searchedTicket);
            $existsStmt->execute();
            $existsRow = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            $inTickets = (int) ($existsRow['in_tickets'] ?? 0) === 1;
            $inGenerated = (int) ($existsRow['in_generated'] ?? 0) === 1;

            if (!$inTickets && !$inGenerated) {
                $resultState = 'error';
                $resultTitle = 'Ticket Not Found';
                $resultMessage = 'No ticket record was found in tickets or generated_tickets.';
            } elseif (!$inTickets && $inGenerated) {
                $resultState = 'error';
                $resultTitle = 'Not Activated';
                $resultMessage = 'Ticket exists in generated records but has not been activated in the tickets table.';
            } else {
                $detailSql = "SELECT
                        t.ticket_number,
                        LOWER(COALESCE(t.status, 'pending')) AS ticket_status,
                        t.assigned_chapter_id,
                        ta.buyer_id,
                        b.full_name AS buyer_name,
                        b.email AS buyer_email,
                        b.contact AS buyer_contact,
                        b.chapter AS buyer_chapter,
                        b.remarks AS buyer_remarks,
                        COALESCE(u.chapter_name, u.name, u.username) AS chapter_name,
                        u.email AS chapter_email,
                        u.remarks AS chapter_remarks,
                        EXISTS(SELECT 1 FROM ticket_activations act WHERE act.ticket_number = t.ticket_number LIMIT 1) AS activation_logged
                    FROM tickets t
                    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
                    LEFT JOIN buyers b ON b.id = ta.buyer_id
                    LEFT JOIN users u ON u.id = t.assigned_chapter_id AND LOWER(u.role) = 'chapter'
                    WHERE t.ticket_number = ?
                    LIMIT 1";

                $detailStmt = $conn->prepare($detailSql);
                if (!$detailStmt) {
                    $resultState = 'error';
                    $resultTitle = 'Lookup Error';
                    $resultMessage = 'Unable to fetch ticket details for winner validation.';
                } else {
                    $detailStmt->bind_param('s', $searchedTicket);
                    $detailStmt->execute();
                    $ticketRow = $detailStmt->get_result()->fetch_assoc();
                    $detailStmt->close();

                    if (!$ticketRow) {
                        $resultState = 'error';
                        $resultTitle = 'Ticket Not Found';
                        $resultMessage = 'Ticket exists in registry but no active ticket record is currently available.';
                    } else {
                        $isActivated = strtolower((string) ($ticketRow['ticket_status'] ?? 'pending')) === 'activated'
                            || (int) ($ticketRow['activation_logged'] ?? 0) === 1;

                        $buyerId = (int) ($ticketRow['buyer_id'] ?? 0);
                        $chapterId = (int) ($ticketRow['assigned_chapter_id'] ?? 0);

                        $ownerType = 'none';
                        $ownerName = '';
                        $ownerEmail = '';
                        $ownerContact = '';
                        $remarks = '';

                        if ($buyerId > 0) {
                            $ownerType = 'buyer';
                            $ownerName = trim((string) ($ticketRow['buyer_name'] ?? ''));
                            $ownerEmail = trim((string) ($ticketRow['buyer_email'] ?? ''));
                            $ownerContact = trim((string) ($ticketRow['buyer_contact'] ?? ''));
                            $remarks = trim((string) ($ticketRow['buyer_remarks'] ?? ''));
                        } elseif ($chapterId > 0) {
                            $ownerType = 'chapter';
                            $ownerName = trim((string) ($ticketRow['chapter_name'] ?? ''));
                            $ownerEmail = trim((string) ($ticketRow['chapter_email'] ?? ''));
                            $remarks = trim((string) ($ticketRow['chapter_remarks'] ?? ''));
                        }

                        $isPaid = $remarks !== '' && stripos($remarks, 'paid') !== false;

                        $winnerData = [
                            'ticket_number' => (string) ($ticketRow['ticket_number'] ?? $searchedTicket),
                            'is_activated' => $isActivated,
                            'owner_type' => $ownerType,
                            'owner_name' => $ownerName,
                            'owner_email' => $ownerEmail,
                            'owner_contact' => $ownerContact,
                            'chapter_name' => trim((string) ($ticketRow['buyer_chapter'] ?? '')),
                            'remarks' => $remarks,
                            'is_paid' => $isPaid,
                        ];

                        if (!$isActivated) {
                            $resultState = 'error';
                            $resultTitle = 'Not Activated';
                            $resultMessage = 'This ticket is not activated yet and cannot be included in the draw.';
                        } elseif ($ownerType === 'none') {
                            $resultState = 'error';
                            $resultTitle = 'Not Eligible';
                            $resultMessage = 'This ticket has no buyer/chapter ownership record yet.';
                        } elseif (!$isPaid) {
                            $resultState = 'error';
                            $resultTitle = 'Not Eligible';
                            $resultMessage = 'Owner remarks do not contain "paid". Ticket is excluded from the winner draw.';
                        } else {
                            $resultState = 'winner';
                            $resultTitle = 'Eligible Winner Found';
                            $resultMessage = 'Ticket passed activation and payment checks.';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick A Winner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Bebas+Neue&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #05203c;
            --teal: #0e7490;
            --sun: #f59e0b;
            --mint: #34d399;
            --ink: #0f172a;
            --panel: rgba(255, 255, 255, 0.16);
            --panel-border: rgba(255, 255, 255, 0.3);
            --text: #e2e8f0;
            --danger: #ff6b6b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 20%, rgba(52, 211, 153, 0.3) 0, transparent 35%),
                radial-gradient(circle at 90% 15%, rgba(245, 158, 11, 0.28) 0, transparent 35%),
                radial-gradient(circle at 70% 80%, rgba(14, 116, 144, 0.38) 0, transparent 45%),
                linear-gradient(130deg, #041224 0%, #0b3057 50%, #0f3d66 100%);
            overflow-x: hidden;
        }

        .halo {
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            filter: blur(50px);
            opacity: 0.35;
            pointer-events: none;
            animation: float 9s ease-in-out infinite;
        }

        .halo-a { top: -120px; left: -80px; background: #34d399; }
        .halo-b { bottom: -140px; right: -80px; background: #f59e0b; animation-delay: 1.4s; }

        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            50% { transform: translateY(24px) translateX(-16px); }
        }

        .page {
            width: min(1080px, 92vw);
            margin: 32px auto;
            position: relative;
            z-index: 1;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .back-link {
            color: #dbeafe;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(219, 234, 254, 0.45);
            border-radius: 999px;
            padding: 8px 14px;
            transition: 0.25s ease;
        }

        .back-link:hover {
            background: rgba(219, 234, 254, 0.2);
            color: #ffffff;
        }

        .brand {
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: 0.08em;
            font-size: clamp(1.5rem, 4vw, 2.2rem);
            margin: 0;
        }

        .hero-card, .result-card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            backdrop-filter: blur(14px);
            border-radius: 24px;
            box-shadow: 0 26px 90px rgba(3, 8, 24, 0.38);
        }

        .hero-card {
            padding: clamp(20px, 4vw, 36px);
            margin-bottom: 18px;
        }

        .hero-title {
            margin: 0;
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(2.6rem, 7vw, 5.8rem);
            line-height: 0.95;
            letter-spacing: 0.04em;
            color: #f8fafc;
        }

        .hero-subtitle {
            margin-top: 12px;
            color: #cbd5e1;
            max-width: 740px;
        }

        .search-form {
            margin-top: 24px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        .ticket-input {
            width: 100%;
            border: 2px solid rgba(226, 232, 240, 0.4);
            background: rgba(2, 6, 23, 0.35);
            color: #f8fafc;
            border-radius: 14px;
            font-size: clamp(1.15rem, 2.8vw, 1.7rem);
            font-weight: 800;
            letter-spacing: 0.04em;
            padding: 14px 16px;
            text-transform: uppercase;
            outline: none;
            transition: border-color 0.2s ease, transform 0.2s ease;
        }

        .ticket-input:focus {
            border-color: #34d399;
            transform: translateY(-1px);
        }

        .draw-btn {
            border: none;
            border-radius: 14px;
            padding: 0 22px;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            color: #082f49;
            background: linear-gradient(135deg, #facc15 0%, #34d399 100%);
            box-shadow: 0 12px 30px rgba(52, 211, 153, 0.32);
            transition: transform 0.2s ease;
        }

        .draw-btn:hover {
            transform: translateY(-2px) scale(1.01);
        }

        .result-card {
            padding: clamp(20px, 4vw, 32px);
            opacity: 0;
            transform: translateY(16px) scale(0.98);
            animation: reveal 0.55s ease forwards;
        }

        @keyframes reveal {
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .state-pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .state-idle { background: rgba(148, 163, 184, 0.22); color: #dbeafe; }
        .state-winner { background: rgba(52, 211, 153, 0.2); color: #86efac; }
        .state-error { background: rgba(255, 107, 107, 0.2); color: #fecaca; }

        .result-title {
            margin: 0;
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(2rem, 5vw, 3.6rem);
            letter-spacing: 0.04em;
            line-height: 1;
        }

        .result-message {
            margin-top: 10px;
            margin-bottom: 20px;
            color: #e2e8f0;
        }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }

        .data-chip {
            background: rgba(2, 6, 23, 0.35);
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 14px;
            padding: 12px;
        }

        .data-chip .label {
            display: block;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 4px;
        }

        .data-chip .value {
            font-size: 1rem;
            font-weight: 700;
            color: #f8fafc;
            word-break: break-word;
        }

        .winner-burst {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .winner-burst i {
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fde68a;
            animation: burst 1.8s ease-out infinite;
            opacity: 0;
        }

        .winner-burst i:nth-child(2n) { background: #6ee7b7; }
        .winner-burst i:nth-child(3n) { background: #93c5fd; }

        .winner .winner-burst i:nth-child(1) { left: 10%; top: 20%; animation-delay: 0.12s; }
        .winner .winner-burst i:nth-child(2) { left: 24%; top: 12%; animation-delay: 0.22s; }
        .winner .winner-burst i:nth-child(3) { left: 40%; top: 16%; animation-delay: 0.34s; }
        .winner .winner-burst i:nth-child(4) { left: 68%; top: 18%; animation-delay: 0.4s; }
        .winner .winner-burst i:nth-child(5) { left: 82%; top: 24%; animation-delay: 0.52s; }
        .winner .winner-burst i:nth-child(6) { left: 16%; top: 74%; animation-delay: 0.3s; }
        .winner .winner-burst i:nth-child(7) { left: 34%; top: 80%; animation-delay: 0.45s; }
        .winner .winner-burst i:nth-child(8) { left: 58%; top: 76%; animation-delay: 0.2s; }
        .winner .winner-burst i:nth-child(9) { left: 74%; top: 82%; animation-delay: 0.62s; }

        @keyframes burst {
            0% { transform: scale(0.2); opacity: 0; }
            12% { opacity: 1; }
            100% { transform: scale(1.65) translateY(-12px); opacity: 0; }
        }

        @media (max-width: 768px) {
            .page { width: min(1100px, 94vw); margin: 18px auto 24px; }
            .topbar { align-items: flex-start; flex-direction: column-reverse; }
            .search-form { grid-template-columns: 1fr; }
            .draw-btn { height: 52px; }
        }
    </style>
</head>
<body>
<div class="halo halo-a"></div>
<div class="halo halo-b"></div>

<div class="page">
    <div class="topbar">
        <a class="back-link" href="<?php echo htmlspecialchars($dashboardPath, ENT_QUOTES); ?>">Back to Dashboard</a>
        <p class="brand">CPM Raffle Draw Console</p>
    </div>

    <section class="hero-card">
        <h1 class="hero-title">Pick A Winner</h1>
        <p class="hero-subtitle">Validate ticket activation, ownership, and payment remarks before announcing a winner on stage.</p>

        <form method="POST" class="search-form" autocomplete="off">
            <input
                type="text"
                name="ticket_number"
                class="ticket-input"
                maxlength="80"
                placeholder="Enter ticket number (e.g. PSMECPMXWEN_00001)"
                value="<?php echo htmlspecialchars($searchedTicket, ENT_QUOTES); ?>"
                required>
            <button type="submit" class="draw-btn">Run Winner Check</button>
        </form>
    </section>

    <section class="result-card <?php echo $resultState === 'winner' ? 'winner' : ''; ?>">
        <div class="winner-burst" aria-hidden="true">
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
        </div>

        <div class="state-pill state-<?php echo htmlspecialchars($resultState, ENT_QUOTES); ?>">
            <?php echo htmlspecialchars(strtoupper($resultState), ENT_QUOTES); ?>
        </div>
        <h2 class="result-title"><?php echo htmlspecialchars($resultTitle, ENT_QUOTES); ?></h2>
        <p class="result-message"><?php echo htmlspecialchars($resultMessage, ENT_QUOTES); ?></p>

        <?php if ($searchedTicket !== ''): ?>
            <div class="data-grid">
                <div class="data-chip">
                    <span class="label">Ticket Number</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['ticket_number'] ?? $searchedTicket), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Activated</span>
                    <span class="value"><?php echo !empty($winnerData['is_activated']) ? 'Yes' : 'No'; ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Owner Type</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['owner_type'] ?? 'none'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Owner Name</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['owner_name'] ?? '-'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Owner Email</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['owner_email'] ?? '-'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Owner Contact</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['owner_contact'] ?? '-'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Buyer Chapter</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['chapter_name'] ?? '-'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Remarks</span>
                    <span class="value"><?php echo htmlspecialchars((string) ($winnerData['remarks'] ?? '-'), ENT_QUOTES); ?></span>
                </div>
                <div class="data-chip">
                    <span class="label">Paid Rule Matched</span>
                    <span class="value"><?php echo !empty($winnerData['is_paid']) ? 'Yes' : 'No'; ?></span>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
