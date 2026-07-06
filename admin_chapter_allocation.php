<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/ticket_mailer.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);

$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());
$isAdmin = auth_is_admin();
$flashMessage = '';
$flashType = 'success';

$chapterName = trim((string) ($_POST['chapter_name'] ?? ''));
$presidentEmail = trim((string) ($_POST['president_email'] ?? ''));
$rangeStartInput = trim((string) ($_POST['range_start'] ?? ''));
$rangeEndInput = trim((string) ($_POST['range_end'] ?? ''));
$createdUsername = '';
$generatedPassword = '';

function admin_chapter_generate_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

function admin_chapter_extract_ticket_number(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }

    // Accept plain numbers (e.g., 1, 00050) or full ticket codes (e.g., PSMECPMXWEN_00050).
    if (preg_match('/^\d+$/', $trimmed) === 1) {
        return (int) ltrim($trimmed, '0') ?: 0;
    }

    if (preg_match('/^[A-Za-z0-9_]+[-_](\d+)$/', $trimmed, $matches) !== 1) {
        return 0;
    }

    return (int) (ltrim($matches[1], '0') ?: 0);
}

function admin_chapter_format_ticket(string $prefix, int $number): string
{
    return $prefix . '_' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

function admin_chapter_bind_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '') {
        return true;
    }

    $bind = [$types];
    foreach ($values as $index => $_value) {
        $bind[] = &$values[$index];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($chapterName === '') {
            throw new RuntimeException('Chapter name is required.');
        }
        if ($presidentEmail === '' || !filter_var($presidentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid chapter president email is required.');
        }

        $startNumber = admin_chapter_extract_ticket_number($rangeStartInput);
        $endNumber = admin_chapter_extract_ticket_number($rangeEndInput);
        if ($startNumber <= 0 || $endNumber <= 0 || $endNumber < $startNumber) {
            throw new RuntimeException('Invalid ticket range. Enter plain ticket numbers (example: 1 to 50) or full codes.');
        }
        if (($endNumber - $startNumber + 1) > 3000) {
            throw new RuntimeException('Ticket range too large. Please allocate up to 3000 tickets at a time.');
        }

        $prefix = getTicketNumberPrefix($conn);
        $ticketNumbers = [];
        for ($i = $startNumber; $i <= $endNumber; $i++) {
            $ticketNumbers[] = admin_chapter_format_ticket($prefix, $i);
        }

        $createdUsername = strtolower($presidentEmail);
        $generatedPassword = admin_chapter_generate_password();

        $conn->begin_transaction();

        $existingStmt = $conn->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(COALESCE(email, "")) = LOWER(?) LIMIT 1');
        if (!$existingStmt) {
            throw new RuntimeException('Unable to validate existing chapter accounts.');
        }
        $existingStmt->bind_param('ss', $createdUsername, $presidentEmail);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
        $existingStmt->close();

        if ($existingRow) {
            throw new RuntimeException('A user account already exists for the provided email/username.');
        }

        $placeholderSql = implode(',', array_fill(0, count($ticketNumbers), '?'));
        $validationSql = 'SELECT t.ticket_number,
                LOWER(COALESCE(t.status, "")) AS ticket_status,
                COALESCE(t.assigned_chapter_id, 0) AS assigned_chapter_id,
                ta.ticket_number AS assigned_ticket
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number IN (' . $placeholderSql . ')
            FOR UPDATE';
        $validationStmt = $conn->prepare($validationSql);
        if (!$validationStmt) {
            throw new RuntimeException('Unable to validate the selected ticket range.');
        }

        $validationTypes = str_repeat('s', count($ticketNumbers));
        $validationValues = $ticketNumbers;
        if (!admin_chapter_bind_params($validationStmt, $validationTypes, $validationValues)) {
            $validationStmt->close();
            throw new RuntimeException('Unable to bind ticket validation values.');
        }

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

        foreach ($validatedRows as $validatedRow) {
            if (!empty($validatedRow['assigned_ticket'])) {
                throw new RuntimeException('One or more tickets are already assigned to buyers.');
            }
            if ((int) ($validatedRow['assigned_chapter_id'] ?? 0) > 0) {
                throw new RuntimeException('One or more tickets are already allocated to a chapter.');
            }
            if (strtolower((string) ($validatedRow['ticket_status'] ?? '')) !== 'pending') {
                throw new RuntimeException('One or more tickets are not pending and cannot be allocated.');
            }
        }

        $hashedPassword = password_hash($generatedPassword, PASSWORD_DEFAULT);
        $role = 'chapter';
        $insertUserStmt = $conn->prepare('INSERT INTO users (name, username, password, role, email, chapter_name) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$insertUserStmt) {
            throw new RuntimeException('Unable to create chapter portal account.');
        }
        $insertUserStmt->bind_param('ssssss', $chapterName, $createdUsername, $hashedPassword, $role, $presidentEmail, $chapterName);
        $insertUserStmt->execute();
        $chapterId = (int) $insertUserStmt->insert_id;
        $insertUserStmt->close();

        $updateTicketStmt = $conn->prepare('UPDATE tickets SET assigned_chapter_id = ?, chapter_assigned_at = NOW() WHERE ticket_number = ?');
        if (!$updateTicketStmt) {
            throw new RuntimeException('Unable to update ticket chapter allocation.');
        }
        foreach ($ticketNumbers as $ticketNumber) {
            $updateTicketStmt->bind_param('is', $chapterId, $ticketNumber);
            $updateTicketStmt->execute();
        }
        $updateTicketStmt->close();

        $allocationStmt = $conn->prepare('INSERT INTO chapter_allocations (chapter_id, admin_id, start_ticket, end_ticket) VALUES (?, ?, ?, ?)');
        if (!$allocationStmt) {
            throw new RuntimeException('Unable to record chapter allocation.');
        }
        $adminId = auth_user_id();
        $startTicket = $ticketNumbers[0];
        $endTicket = $ticketNumbers[count($ticketNumbers) - 1];
        $allocationStmt->bind_param('iiss', $chapterId, $adminId, $startTicket, $endTicket);
        $allocationStmt->execute();
        $allocationStmt->close();

        $conn->commit();

        try {
            ticket_send_chapter_invitation($appConfig, [
                'chapter_name' => $chapterName,
                'president_email' => $presidentEmail,
                'username' => $createdUsername,
                'password' => $generatedPassword,
                'ticket_range_summary' => $startTicket . ' to ' . $endTicket,
                'ticket_count' => (string) count($ticketNumbers),
            ]);

            $flashMessage = 'Chapter account created, tickets allocated, and invitation email sent successfully.';
            $flashType = 'success';
        } catch (Throwable $mailException) {
            $flashMessage = 'Chapter account and ticket allocation completed, but invitation email failed: ' . $mailException->getMessage();
            $flashType = 'warning';
        }
    } catch (Throwable $exception) {
        @$conn->rollback();
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
        $generatedPassword = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Chapter Allocation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
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
        .shell { max-width: 1050px; margin-left: 280px; width: calc(100% - 280px); padding: 28px 16px 48px; }
        .hero, .card { background: #ffffff; border: 1px solid #e8eef5; border-radius: 18px; box-shadow: 0 18px 60px rgba(40, 53, 85, 0.12); }
        .hero { padding: 26px; margin-bottom: 20px; }
        .hero h1 { margin: 0 0 8px; color: #283555; font-size: 1.8rem; font-weight: 700; }
        .muted { color: #6b7280; }
        .btn-primary { background: #283555; border-color: #283555; }
        .btn-primary:hover { background: #1f2943; border-color: #1f2943; }
        .alert { border-radius: 12px; }
        .code-pill { font-family: monospace; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.2rem 0.45rem; }
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
    <?php render_dashboard_sidebar('/CPMraffle/admin_chapter_allocation.php', $isAdmin, $dashboardPath); ?>

<div class="shell">
    <div class="hero">
        <h1>Chapter Management & Ticket Allocation</h1>
        <p class="mb-0 muted">Create a chapter portal account and allocate an exact ticket range in one step.</p>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="card p-4 mb-3">
        <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label" for="chapter_name">Chapter Name</label>
                <input id="chapter_name" name="chapter_name" type="text" class="form-control" maxlength="120" value="<?php echo htmlspecialchars($chapterName, ENT_QUOTES); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="president_email">Chapter President Email</label>
                <input id="president_email" name="president_email" type="email" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($presidentEmail, ENT_QUOTES); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="range_start">Ticket Start Number</label>
                <input id="range_start" name="range_start" type="text" class="form-control" placeholder="1" value="<?php echo htmlspecialchars($rangeStartInput, ENT_QUOTES); ?>" required>
                <div class="form-text">You can enter just the number (for example, 1).</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="range_end">Ticket End Number</label>
                <input id="range_end" name="range_end" type="text" class="form-control" placeholder="50" value="<?php echo htmlspecialchars($rangeEndInput, ENT_QUOTES); ?>" required>
                <div class="form-text">Prefix is optional. Example: 50 or PSMECPMXWEN_00050.</div>
            </div>
            <div class="col-12">
                <div id="range_validation_indicator" class="mt-2"></div>
            </div>
            <div class="col-md-4 d-grid">
                <button type="submit" class="btn btn-primary" id="allocate_submit_btn">Create & Allocate</button>
            </div>
        </form>
    </div>

    <?php if ($generatedPassword !== '' && $flashType !== 'danger'): ?>
        <div class="alert alert-info">
            Temporary credentials created: Username <span class="code-pill"><?php echo htmlspecialchars($createdUsername, ENT_QUOTES); ?></span> and password <span class="code-pill"><?php echo htmlspecialchars($generatedPassword, ENT_QUOTES); ?></span>
        </div>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap">
        <a href="<?php echo htmlspecialchars($dashboardPath, ENT_QUOTES); ?>" class="btn btn-outline-secondary">Back to Dashboard</a>
        <a href="/CPMraffle/admin_chapter_list.php" class="btn btn-outline-primary">Open Chapter Monitoring List</a>
    </div>
</div>
</div>
<script>
(function () {
    const rangeStart = document.getElementById('range_start');
    const rangeEnd = document.getElementById('range_end');
    const indicator = document.getElementById('range_validation_indicator');
    const submitBtn = document.getElementById('allocate_submit_btn');
    let debounceTimer = null;

    function debounce(fn, delay) {
        return function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fn, delay);
        };
    }

    function validateRange() {
        const start = rangeStart.value.trim();
        const end = rangeEnd.value.trim();

        if (start === '' || end === '') {
            indicator.innerHTML = '';
            submitBtn.disabled = false;
            return;
        }

        indicator.innerHTML = '<div class="alert alert-secondary py-1 px-3 mb-0">Checking availability&hellip;</div>';
        submitBtn.disabled = true;

        const url = '/CPMraffle/check_ticket_range_api.php?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.available) {
                    indicator.innerHTML = '<div class="alert alert-success py-1 px-3 mb-0"><i class="fa fa-check-circle me-1"></i>' + escapeHtml(data.message) + '</div>';
                    submitBtn.disabled = false;
                } else {
                    indicator.innerHTML = '<div class="alert alert-danger py-1 px-3 mb-0"><i class="fa fa-exclamation-circle me-1"></i>' + escapeHtml(data.message) + '</div>';
                    submitBtn.disabled = true;
                }
            })
            .catch(function () {
                indicator.innerHTML = '';
                submitBtn.disabled = false;
            });
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    const debouncedValidate = debounce(validateRange, 500);
    rangeStart.addEventListener('input', debouncedValidate);
    rangeEnd.addEventListener('input', debouncedValidate);
})();
</script>
</body>
</html>
