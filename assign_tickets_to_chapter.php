<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/ticket_mailer.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);
ensureEmailTemplateTables($conn, $appConfig);

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

function chapter_alloc_ticket_number_to_int(string $ticketNumber): int
{
    if (preg_match('/[-_](\d+)$/', $ticketNumber, $matches) !== 1) {
        return 0;
    }

    return (int) $matches[1];
}

function chapter_alloc_format_ticket_number(string $prefix, int $number): string
{
    return $prefix . '_' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

$flashMessage = '';
$flashType = 'success';
$prefix = getTicketNumberPrefix($conn);
$chapters = [];
$chapterResult = $conn->query("SELECT id, username, name, email, chapter_name
    FROM users
    WHERE LOWER(role) = 'chapter'
    ORDER BY COALESCE(chapter_name, name, username) ASC");
if ($chapterResult instanceof mysqli_result) {
    while ($row = $chapterResult->fetch_assoc()) {
        $chapters[] = $row;
    }
    $chapterResult->close();
}

$selectedChapterId = (int) ($_POST['chapter_id'] ?? 0);
$rangeStart = trim((string) ($_POST['range_start'] ?? ''));
$rangeEnd = trim((string) ($_POST['range_end'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($selectedChapterId <= 0) {
            throw new RuntimeException('Select a chapter account.');
        }
        if ($rangeStart === '' || $rangeEnd === '') {
            throw new RuntimeException('Both start and end ticket numbers are required.');
        }

        $chapterStmt = $conn->prepare("SELECT id, username, name, email, chapter_name
            FROM users
            WHERE id = ? AND LOWER(role) = 'chapter'
            LIMIT 1");
        if (!$chapterStmt) {
            throw new RuntimeException('Unable to read chapter account.');
        }
        $chapterStmt->bind_param('i', $selectedChapterId);
        $chapterStmt->execute();
        $chapter = $chapterStmt->get_result()->fetch_assoc();
        $chapterStmt->close();
        if (!$chapter) {
            throw new RuntimeException('Selected chapter account does not exist.');
        }

        $rangeStartNumber = chapter_alloc_ticket_number_to_int($rangeStart);
        $rangeEndNumber = chapter_alloc_ticket_number_to_int($rangeEnd);
        if ($rangeStartNumber <= 0 || $rangeEndNumber <= 0 || $rangeEndNumber < $rangeStartNumber) {
            throw new RuntimeException('Invalid ticket range.');
        }
        if (($rangeEndNumber - $rangeStartNumber) > 1000) {
            throw new RuntimeException('Range is too large.');
        }

        $ticketNumbers = [];
        for ($i = $rangeStartNumber; $i <= $rangeEndNumber; $i++) {
            $ticketNumbers[] = chapter_alloc_format_ticket_number($prefix, $i);
        }

        $placeholders = implode(',', array_fill(0, count($ticketNumbers), '?'));
        $validationSql = "SELECT t.ticket_number, LOWER(COALESCE(t.status, '')) AS ticket_status,
                COALESCE(t.assigned_chapter_id, 0) AS assigned_chapter_id,
                ta.ticket_number AS assigned_ticket
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number IN ($placeholders)
            FOR UPDATE";
        $validationStmt = $conn->prepare($validationSql);
        if (!$validationStmt) {
            throw new RuntimeException('Unable to validate ticket range.');
        }

        $types = str_repeat('s', count($ticketNumbers));
        $bindValues = [$types];
        foreach ($ticketNumbers as $index => $ticketNumber) {
            $bindValues[] = &$ticketNumbers[$index];
        }
        call_user_func_array([$validationStmt, 'bind_param'], $bindValues);
        $validationStmt->execute();
        $validationResult = $validationStmt->get_result();
        $validatedRows = [];
        while ($validationResult && ($row = $validationResult->fetch_assoc())) {
            $validatedRows[] = $row;
        }
        $validationStmt->close();

        if (count($validatedRows) !== count($ticketNumbers)) {
            throw new RuntimeException('One or more tickets in the range do not exist.');
        }

        foreach ($validatedRows as $row) {
            if (!empty($row['assigned_ticket'])) {
                throw new RuntimeException('One or more tickets are already assigned to a buyer.');
            }
            if ((int) ($row['assigned_chapter_id'] ?? 0) > 0) {
                throw new RuntimeException('One or more tickets are already allocated to a chapter.');
            }
            if (strtolower((string) ($row['ticket_status'] ?? '')) !== 'pending') {
                throw new RuntimeException('One or more tickets are not available for chapter allocation.');
            }
        }

        $conn->begin_transaction();

        $updateStmt = $conn->prepare('UPDATE tickets SET assigned_chapter_id = ?, chapter_assigned_at = NOW() WHERE ticket_number = ?');
        if (!$updateStmt) {
            throw new RuntimeException('Unable to update ticket allocation.');
        }
        $insertAllocation = $conn->prepare('INSERT INTO chapter_allocations (chapter_id, admin_id, start_ticket, end_ticket) VALUES (?, ?, ?, ?)');
        if (!$insertAllocation) {
            throw new RuntimeException('Unable to record chapter allocation.');
        }

        $adminId = auth_user_id();
        $startTicket = $ticketNumbers[0];
        $endTicket = $ticketNumbers[count($ticketNumbers) - 1];
        $insertAllocation->bind_param('iiss', $selectedChapterId, $adminId, $startTicket, $endTicket);
        $insertAllocation->execute();

        foreach ($ticketNumbers as $ticketNumber) {
            $updateStmt->bind_param('is', $selectedChapterId, $ticketNumber);
            $updateStmt->execute();
        }

        $updateStmt->close();
        $insertAllocation->close();
        $conn->commit();

        $chapterName = trim((string) ($chapter['chapter_name'] ?? $chapter['name'] ?? $chapter['username'] ?? 'Chapter'));
        ticket_send_chapter_invitation($appConfig, [
            'chapter_name' => $chapterName,
            'president_email' => (string) ($chapter['email'] ?? ''),
            'username' => (string) ($chapter['username'] ?? ''),
            'password' => 'Use your existing chapter portal credentials',
            'ticket_range_summary' => $startTicket . ' to ' . $endTicket,
            'ticket_count' => (string) count($ticketNumbers),
        ]);

        $flashMessage = 'Allocated ' . count($ticketNumbers) . ' tickets to ' . $chapterName . ' and sent the update email.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        if ($conn->errno === 0) {
            $conn->rollback();
        }
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Tickets to Chapter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { margin: 0; min-height: 100vh; background: linear-gradient(135deg, #f4f6f9 0%, #ffffff 100%); color: #2c3e50; }
        .dashboard-container { display: flex; min-height: 100vh; }
        aside.sidebar {
            width: 250px;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            color: #f4f6f9;
            padding: 22px 16px 30px;
            box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
        }
        aside.sidebar .sidebar-header { text-align: center; margin-bottom: 10px; }
        aside.sidebar .sidebar-header h1 { font-size: 16px; color: #fff; text-transform: uppercase; margin: 0; }
        aside.sidebar nav ul { list-style: none; padding: 0; margin-top: 10px; }
        aside.sidebar nav ul li { margin: 10px 0; }
        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            color: #f4f6f9;
            text-decoration: none;
            font-size: 15px;
            transition: background .3s ease, padding-left .3s ease;
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
            border-left: 3px solid #ff6b35;
        }
        .shell { max-width: 1100px; margin-left: 280px; width: calc(100% - 280px); padding: 32px 16px 48px; }
        .hero, .card { background: #fff; border: 1px solid #e8eef5; border-radius: 18px; box-shadow: 0 18px 60px rgba(40, 53, 85, 0.12); }
        .hero { padding: 28px; margin-bottom: 20px; }
        .hero h1 { margin: 0 0 10px; color: #283555; font-size: 1.9rem; font-weight: 700; }
        .muted { color: #6b7280; }
        .btn-primary { background: #283555; border-color: #283555; }
        .btn-primary:hover { background: #1f2943; border-color: #1f2943; }
        .alert { border-radius: 12px; }
        .table thead th { background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%); color: #fff; border: none; }
        @media (max-width: 768px) {
            .dashboard-container { flex-direction: column; }
            aside.sidebar {
                width: 100%;
                min-height: auto;
                position: relative;
                box-shadow: none;
            }
            .shell {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
                padding: 16px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/assign_tickets_to_chapter.php', $isAdmin, $dashboardPath); ?>

<div class="shell">
    <div class="hero">
        <h1>Assign Tickets to Chapter</h1>
        <p class="mb-0 muted">Allocate an exact range of unassigned tickets to a chapter account.</p>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Chapter Account</label>
                <select name="chapter_id" class="form-select" required>
                    <option value="">Select chapter</option>
                    <?php foreach ($chapters as $chapter): ?>
                        <?php $label = trim((string) ($chapter['chapter_name'] ?? $chapter['name'] ?? $chapter['username'] ?? '')); ?>
                        <option value="<?php echo (int) $chapter['id']; ?>" <?php echo $selectedChapterId === (int) $chapter['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($label !== '' ? $label : 'Chapter #' . (int) $chapter['id'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Start Ticket</label>
                <input type="text" name="range_start" class="form-control" placeholder="PSMECPMXWEN_00001" value="<?php echo htmlspecialchars($rangeStart, ENT_QUOTES); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">End Ticket</label>
                <input type="text" name="range_end" class="form-control" placeholder="PSMECPMXWEN_00050" value="<?php echo htmlspecialchars($rangeEnd, ENT_QUOTES); ?>" required>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-primary">Allocate</button>
            </div>
        </form>
    </div>

    <div class="card p-4">
        <h2 class="h5 mb-3">Available Chapter Accounts</h2>
        <?php if ($chapters === []): ?>
            <div class="alert alert-warning mb-0">No chapter accounts exist yet. Create one first.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Chapter</th>
                        <th>Username</th>
                        <th>Email</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($chapters as $chapter): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(trim((string) ($chapter['chapter_name'] ?? $chapter['name'] ?? '')), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string) ($chapter['username'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string) ($chapter['email'] ?? ''), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
