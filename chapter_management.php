<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/ticket_mailer.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);
ensureEmailTemplateTables($conn, $appConfig);

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

$flashMessage = '';
$flashType = 'success';
$generatedPassword = '';
$createdChapterId = 0;
$chapterName = '';
$presidentEmail = '';
$username = '';
$chapterRows = [];
$allowedRemarks = ['', 'Paid', 'Fully Paid', 'Partially Paid', 'Pending', 'Not Paid'];

function chapter_generate_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    $maxIndex = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'create_chapter'));

    try {
        if ($action === 'update_remarks') {
            $chapterId = (int) ($_POST['chapter_id'] ?? 0);
            $remarks = trim((string) ($_POST['remarks'] ?? ''));
            if ($chapterId <= 0) {
                throw new RuntimeException('Invalid chapter selected.');
            }
            if (!in_array($remarks, $allowedRemarks, true)) {
                throw new RuntimeException('Invalid remarks value selected.');
            }

            $remarksValue = $remarks !== '' ? $remarks : null;
            $remarksStmt = $conn->prepare("UPDATE users SET remarks = ?, updated_at = NOW() WHERE id = ? AND LOWER(role) = 'chapter'");
            if (!$remarksStmt) {
                throw new RuntimeException('Unable to update chapter remarks.');
            }

            $remarksStmt->bind_param('si', $remarksValue, $chapterId);
            $remarksStmt->execute();
            $remarksStmt->close();

            $flashMessage = 'Chapter remarks updated successfully.';
            $flashType = 'success';
        } else {
            $chapterName = trim((string) ($_POST['chapter_name'] ?? ''));
            $presidentEmail = trim((string) ($_POST['president_email'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? $presidentEmail));
            $password = trim((string) ($_POST['password'] ?? ''));

            if ($chapterName === '') {
                throw new RuntimeException('Chapter name is required.');
            }
            if ($presidentEmail === '' || !filter_var($presidentEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid president email is required.');
            }
            if ($username === '') {
                $username = $presidentEmail;
            }
            if ($password === '') {
                $password = chapter_generate_password();
            }

            $checkStmt = $conn->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            if (!$checkStmt) {
                throw new RuntimeException('Unable to validate existing accounts.');
            }
            $checkStmt->bind_param('s', $username);
            $checkStmt->execute();
            $existing = $checkStmt->get_result();
            $checkStmt->close();
            if ($existing && $existing->num_rows > 0) {
                throw new RuntimeException('A chapter account with this username already exists.');
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();

            $insertStmt = $conn->prepare('INSERT INTO users (name, username, password, role, email, chapter_name) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$insertStmt) {
                throw new RuntimeException('Unable to create chapter account.');
            }

            $role = 'chapter';
            $insertStmt->bind_param('ssssss', $chapterName, $username, $hashedPassword, $role, $presidentEmail, $chapterName);
            $insertStmt->execute();
            $createdChapterId = (int) $insertStmt->insert_id;
            $insertStmt->close();
            $conn->commit();

            ticket_send_chapter_invitation($appConfig, [
                'chapter_name' => $chapterName,
                'president_email' => $presidentEmail,
                'username' => $username,
                'password' => $password,
                'ticket_range_summary' => 'Pending allocation',
                'ticket_count' => '0',
            ]);

            $generatedPassword = $password;
            $flashMessage = 'Chapter account created successfully and invitation sent.';
            $flashType = 'success';
        }
    } catch (Throwable $exception) {
        if ($conn->errno === 0 && $conn->more_results()) {
            $conn->rollback();
        } elseif ($conn->errno === 0) {
            $conn->rollback();
        }
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$chapterListSql = "SELECT
        u.id,
        COALESCE(u.chapter_name, u.name, u.username) AS chapter_name,
        u.username,
        u.email,
        u.remarks,
        COUNT(t.id) AS allocated_tickets,
        SUM(CASE WHEN LOWER(COALESCE(t.status, 'pending')) = 'activated' THEN 1 ELSE 0 END) AS activated_tickets
    FROM users u
    LEFT JOIN tickets t ON t.assigned_chapter_id = u.id
    WHERE LOWER(u.role) = 'chapter'
    GROUP BY u.id
    ORDER BY COALESCE(u.chapter_name, u.name, u.username) ASC";
$chapterListResult = $conn->query($chapterListSql);
if ($chapterListResult instanceof mysqli_result) {
    while ($chapterRow = $chapterListResult->fetch_assoc()) {
        $chapterRows[] = $chapterRow;
    }
    $chapterListResult->close();
}

$totalChapters = count($chapterRows);
$totalAllocatedTickets = 0;
$totalActivatedTickets = 0;
foreach ($chapterRows as $chapterSummaryRow) {
    $totalAllocatedTickets += (int) ($chapterSummaryRow['allocated_tickets'] ?? 0);
    $totalActivatedTickets += (int) ($chapterSummaryRow['activated_tickets'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chapter Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f4f6f9 0%, #ffffff 100%);
            color: #2c3e50;
        }
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
        .shell {
            max-width: 1380px;
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 28px 24px 48px;
        }
        .hero, .card {
            background: #ffffff;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            box-shadow: 0 18px 60px rgba(40, 53, 85, 0.12);
        }
        .hero {
            padding: 30px;
            margin-bottom: 20px;
        }
        .hero h1 {
            margin: 0 0 10px;
            color: #283555;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 18px;
        }
        .hero-stat {
            border: 1px solid #e8eef5;
            border-radius: 14px;
            padding: 12px 14px;
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        }
        .hero-stat .label {
            display: block;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .hero-stat .value {
            font-size: 1.45rem;
            font-weight: 700;
            color: #283555;
            line-height: 1;
        }
        .layout-grid {
            display: grid;
            grid-template-columns: minmax(380px, 1.05fr) minmax(340px, 0.95fr);
            gap: 18px;
            align-items: start;
        }
        .create-card {
            padding: 24px;
        }
        .help-card {
            padding: 24px;
            background: linear-gradient(145deg, #283555 0%, #3f5689 100%);
            color: #f8fafc;
            border: none;
        }
        .help-card h2 {
            font-size: 1.05rem;
            margin: 0 0 10px;
            font-weight: 700;
        }
        .help-card p {
            color: #d9e3f4;
            margin-bottom: 12px;
        }
        .help-list {
            margin: 0;
            padding-left: 18px;
        }
        .help-list li {
            margin-bottom: 6px;
            color: #e6edf9;
        }
        .table-card {
            margin-top: 20px;
            padding: 22px;
        }
        .chapter-table {
            min-width: 1040px;
        }
        .table thead th {
            background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%);
            color: #ffffff;
            border: none;
            white-space: nowrap;
            padding: 13px 12px;
            font-size: 0.87rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .chapter-table td {
            padding: 13px 12px;
            vertical-align: middle;
        }
        .chapter-name {
            font-weight: 700;
            color: #243652;
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
        .progress-text {
            font-weight: 600;
        }
        .table-actions {
            text-align: right;
            min-width: 120px;
        }
        .alert { border-radius: 12px; }
        .form-label { font-weight: 600; }
        .btn-primary { background: #283555; border-color: #283555; }
        .btn-primary:hover { background: #1f2943; border-color: #1f2943; }
        .btn-outline-secondary:hover { background: #eef2ff; }
        .muted { color: #6b7280; }
        code { background: #f8fafc; padding: 2px 6px; border-radius: 6px; }
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
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .layout-grid {
                grid-template-columns: 1fr;
            }
            .table-card {
                padding: 14px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/chapter_management.php', $isAdmin, $dashboardPath); ?>

<div class="shell">
    <div class="hero">
        <h1>Chapter Management</h1>
        <p class="mb-0 muted">Create chapter portal accounts, manage payment remarks, and monitor chapter activation performance in one place.</p>
        <div class="hero-grid">
            <div class="hero-stat">
                <span class="label">Total Chapters</span>
                <span class="value"><?php echo (int) $totalChapters; ?></span>
            </div>
            <div class="hero-stat">
                <span class="label">Allocated Tickets</span>
                <span class="value"><?php echo (int) $totalAllocatedTickets; ?></span>
            </div>
            <div class="hero-stat">
                <span class="label">Activated Tickets</span>
                <span class="value"><?php echo (int) $totalActivatedTickets; ?></span>
            </div>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="layout-grid">
        <div class="card create-card">
            <h2 class="h4 mb-3">Create Chapter Account</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_chapter">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Chapter Name</label>
                        <input type="text" name="chapter_name" class="form-control" maxlength="120" value="<?php echo htmlspecialchars($chapterName, ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">President Email</label>
                        <input type="email" name="president_email" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($presidentEmail, ENT_QUOTES); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($username !== '' ? $username : $presidentEmail, ENT_QUOTES); ?>">
                        <div class="form-text">Defaults to the president email if left blank.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="text" name="password" class="form-control" maxlength="60" placeholder="Leave blank to auto-generate">
                        <div class="form-text">A random password will be created if you do not supply one.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">Create Chapter Account</button>
                    <a href="/CPMraffle/admin_dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                </div>
            </form>
        </div>
        <aside class="card help-card">
            <h2>Remarks Workflow</h2>
            <p>Keep eligibility clean and auditable by using standard payment remarks.</p>
            <ul class="help-list">
                <li>Set remarks to Paid or Fully Paid for eligible tickets.</li>
                <li>Use Pending or Not Paid to block winner eligibility.</li>
                <li>Update chapter remarks from the table action button.</li>
            </ul>
        </aside>
    </div>

    <?php if ($generatedPassword !== ''): ?>
        <div class="alert alert-info mt-3">
            New chapter credentials were generated and sent. Username: <code><?php echo htmlspecialchars($username, ENT_QUOTES); ?></code>
        </div>
    <?php endif; ?>

    <div class="card table-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h4 mb-0">Chapter Remarks</h2>
            <p class="mb-0 muted">Use remarks like "Paid" or "Pending" to control winner eligibility.</p>
        </div>

        <?php if ($chapterRows === []): ?>
            <div class="alert alert-warning mb-0">No chapter accounts found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 chapter-table">
                    <thead>
                    <tr>
                        <th>Chapter</th>
                        <th>Email</th>
                        <th>Remarks</th>
                        <th>Activation Progress</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($chapterRows as $row): ?>
                        <?php
                        $remarksValue = trim((string) ($row['remarks'] ?? ''));
                        $remarksClass = strtolower(str_replace(' ', '-', $remarksValue !== '' ? $remarksValue : 'pending'));
                        ?>
                        <tr>
                            <td>
                                <div class="chapter-name"><?php echo htmlspecialchars((string) ($row['chapter_name'] ?? ''), ENT_QUOTES); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars((string) ($row['username'] ?? ''), ENT_QUOTES); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES); ?></td>
                            <td>
                                <span class="remarks-pill <?php echo htmlspecialchars($remarksClass, ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($remarksValue !== '' ? $remarksValue : 'Pending', ENT_QUOTES); ?>
                                </span>
                            </td>
                            <td class="progress-text"><?php echo (int) ($row['activated_tickets'] ?? 0); ?> / <?php echo (int) ($row['allocated_tickets'] ?? 0); ?></td>
                            <td class="table-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#chapterRemarksModal<?php echo (int) ($row['id'] ?? 0); ?>">
                                    Edit Remarks
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($chapterRows as $row): ?>
        <div class="modal fade" id="chapterRemarksModal<?php echo (int) ($row['id'] ?? 0); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Remarks: <?php echo htmlspecialchars((string) ($row['chapter_name'] ?? ''), ENT_QUOTES); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_remarks">
                            <input type="hidden" name="chapter_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">

                            <label class="form-label">Remarks</label>
                            <?php $currentChapterRemarks = trim((string) ($row['remarks'] ?? '')); ?>
                            <select name="remarks" class="form-select" required>
                                <?php foreach ($allowedRemarks as $remarksOption): ?>
                                    <option value="<?php echo htmlspecialchars($remarksOption, ENT_QUOTES); ?>" <?php echo $currentChapterRemarks === $remarksOption ? 'selected' : ''; ?>>
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
    <?php endforeach; ?>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
