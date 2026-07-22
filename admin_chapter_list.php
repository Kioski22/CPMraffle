<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);

$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());
$isAdmin = auth_is_admin();

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'activated'], true)) {
    $statusFilter = 'all';
}

$chapterIdFilter = (int) ($_GET['chapter_id'] ?? 0);

$sortField = strtolower(trim((string) ($_GET['sort'] ?? 'chapter')));
$sortDir = strtolower(trim((string) ($_GET['dir'] ?? 'asc')));
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'asc';
}

$sortMap = [
    'chapter' => 'chapter_name',
    'ticket' => 't.ticket_number',
    'status' => 'ticket_status',
    'owner' => 'owner_name',
];
if (!isset($sortMap[$sortField])) {
    $sortField = 'chapter';
}
$orderSql = $sortMap[$sortField] . ' ' . strtoupper($sortDir) . ', t.ticket_number ASC';

$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) {
    $page = 1;
}
$pageSize = 40;

function admin_chapter_list_bind_params(mysqli_stmt $stmt, string $types, array &$values): bool
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

$chapters = [];
$chapterStmt = $conn->query("SELECT id, COALESCE(chapter_name, name, username) AS chapter_name
    FROM users
    WHERE LOWER(role) = 'chapter'
    ORDER BY COALESCE(chapter_name, name, username) ASC");
if ($chapterStmt instanceof mysqli_result) {
    while ($chapterRow = $chapterStmt->fetch_assoc()) {
        $chapters[] = $chapterRow;
    }
    $chapterStmt->close();
}

$whereParts = ["LOWER(u.role) = 'chapter'", 't.assigned_chapter_id = u.id'];
$types = '';
$params = [];

if ($chapterIdFilter > 0) {
    $whereParts[] = 'u.id = ?';
    $types .= 'i';
    $params[] = $chapterIdFilter;
}

if ($statusFilter !== 'all') {
    $whereParts[] = 'LOWER(COALESCE(t.status, "pending")) = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($searchQuery !== '') {
    $whereParts[] = '(COALESCE(u.chapter_name, u.name, u.username) LIKE ? OR t.ticket_number LIKE ? OR COALESCE(b.full_name, "") LIKE ?)';
    $searchLike = '%' . $searchQuery . '%';
    $types .= 'sss';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}

$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

$totalRows = 0;
$countSql = 'SELECT COUNT(*) AS total_count
    FROM users u
    INNER JOIN tickets t ON t.assigned_chapter_id = u.id
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    LEFT JOIN buyers b ON b.id = ta.buyer_id' . $whereSql;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $countValues = $params;
    if (admin_chapter_list_bind_params($countStmt, $types, $countValues)) {
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : null;
        $totalRows = (int) ($countRow['total_count'] ?? 0);
    }
    $countStmt->close();
}

$totalPages = max(1, (int) ceil($totalRows / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

$rows = [];
$listSql = 'SELECT
        u.id AS chapter_id,
        COALESCE(u.chapter_name, u.name, u.username) AS chapter_name,
        u.email AS chapter_email,
        t.ticket_number,
        LOWER(COALESCE(t.status, "pending")) AS ticket_status,
        COALESCE(b.full_name, "") AS owner_name,
        COALESCE(b.email, "") AS owner_email
    FROM users u
    INNER JOIN tickets t ON t.assigned_chapter_id = u.id
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    LEFT JOIN buyers b ON b.id = ta.buyer_id
    ' . $whereSql . '
    ORDER BY ' . $orderSql . '
    LIMIT ?, ?';
$listStmt = $conn->prepare($listSql);
if ($listStmt) {
    $listTypes = $types . 'ii';
    $listValues = $params;
    $listValues[] = $offset;
    $listValues[] = $pageSize;
    if (admin_chapter_list_bind_params($listStmt, $listTypes, $listValues)) {
        $listStmt->execute();
        $listResult = $listStmt->get_result();
        while ($listResult && ($listRow = $listResult->fetch_assoc())) {
            $rows[] = $listRow;
        }
    }
    $listStmt->close();
}

$fromRow = $totalRows === 0 ? 0 : ($offset + 1);
$toRow = min($totalRows, $offset + $pageSize);
$baseQuery = [
    'q' => $searchQuery,
    'status' => $statusFilter,
    'chapter_id' => $chapterIdFilter,
    'sort' => $sortField,
    'dir' => $sortDir,
];

function admin_chapter_next_dir(string $currentSort, string $currentDir, string $targetSort): string
{
    if ($currentSort === $targetSort) {
        return $currentDir === 'asc' ? 'desc' : 'asc';
    }

    return 'asc';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Chapter List</title>
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
        .shell { max-width: 1280px; margin-left: 280px; width: calc(100% - 280px); padding: 28px 16px 48px; }
        .hero, .card { background: #fff; border: 1px solid #e8eef5; border-radius: 18px; box-shadow: 0 18px 60px rgba(40, 53, 85, 0.12); }
        .hero { padding: 26px; margin-bottom: 20px; }
        .hero h1 { margin: 0 0 8px; color: #283555; font-size: 1.8rem; font-weight: 700; }
        .muted { color: #6b7280; }
        .table thead th { background: linear-gradient(135deg, #283555 0%, #3a4a6f 100%); color: #fff; border: none; white-space: nowrap; }
        .sort-link { color: #fff; text-decoration: none; }
        .sort-link:hover { color: #dbeafe; }
        .status-pill { display: inline-block; border-radius: 999px; padding: 0.2rem 0.55rem; font-weight: 600; font-size: 0.8rem; }
        .status-pill.pending { background: #fef3c7; color: #92400e; }
        .status-pill.activated { background: #dcfce7; color: #166534; }
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
    <?php render_dashboard_sidebar('/CPMraffle/admin_chapter_list.php', $isAdmin, $dashboardPath); ?>

<div class="shell">
    <div class="hero">
        <h1>Chapter Monitoring List</h1>
        <p class="mb-0 muted">Track chapter ticket ownership and activation status in real time.</p>
    </div>

    <div class="card p-4 mb-3">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input id="q" name="q" type="text" class="form-control" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES); ?>" placeholder="Chapter, ticket, owner">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Ticket Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="activated" <?php echo $statusFilter === 'activated' ? 'selected' : ''; ?>>Activated</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="chapter_id">Chapter</label>
                <select id="chapter_id" name="chapter_id" class="form-select">
                    <option value="0">All Chapters</option>
                    <?php foreach ($chapters as $chapter): ?>
                        <option value="<?php echo (int) ($chapter['id'] ?? 0); ?>" <?php echo $chapterIdFilter === (int) ($chapter['id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($chapter['chapter_name'] ?? ''), ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortField, ENT_QUOTES); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sortDir, ENT_QUOTES); ?>">
                <button class="btn btn-primary" type="submit">Apply</button>
                <a class="btn btn-outline-secondary" href="/CPMraffle/admin_chapter_list.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <p class="text-muted mb-0">Showing <?php echo htmlspecialchars((string) $fromRow, ENT_QUOTES); ?>-<?php echo htmlspecialchars((string) $toRow, ENT_QUOTES); ?> of <?php echo htmlspecialchars((string) $totalRows, ENT_QUOTES); ?> records.</p>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary btn-sm" href="/CPMraffle/admin_chapter_allocation.php">Create & Allocate Chapter</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($dashboardPath, ENT_QUOTES); ?>">Back to Dashboard</a>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="alert alert-warning mb-0">No chapter ticket records found for the selected filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <?php
                        $chapterSortDir = admin_chapter_next_dir($sortField, $sortDir, 'chapter');
                        $ticketSortDir = admin_chapter_next_dir($sortField, $sortDir, 'ticket');
                        $statusSortDir = admin_chapter_next_dir($sortField, $sortDir, 'status');
                        $ownerSortDir = admin_chapter_next_dir($sortField, $sortDir, 'owner');
                        $chapterSortQuery = $baseQuery; $chapterSortQuery['sort'] = 'chapter'; $chapterSortQuery['dir'] = $chapterSortDir; $chapterSortQuery['page'] = 1;
                        $ticketSortQuery = $baseQuery; $ticketSortQuery['sort'] = 'ticket'; $ticketSortQuery['dir'] = $ticketSortDir; $ticketSortQuery['page'] = 1;
                        $statusSortQuery = $baseQuery; $statusSortQuery['sort'] = 'status'; $statusSortQuery['dir'] = $statusSortDir; $statusSortQuery['page'] = 1;
                        $ownerSortQuery = $baseQuery; $ownerSortQuery['sort'] = 'owner'; $ownerSortQuery['dir'] = $ownerSortDir; $ownerSortQuery['page'] = 1;
                        ?>
                        <th><a class="sort-link" href="?<?php echo htmlspecialchars(http_build_query($chapterSortQuery), ENT_QUOTES); ?>">Chapter Name</a></th>
                        <th><a class="sort-link" href="?<?php echo htmlspecialchars(http_build_query($ticketSortQuery), ENT_QUOTES); ?>">Ticket Number</a></th>
                        <th><a class="sort-link" href="?<?php echo htmlspecialchars(http_build_query($statusSortQuery), ENT_QUOTES); ?>">Ticket Status</a></th>
                        <th><a class="sort-link" href="?<?php echo htmlspecialchars(http_build_query($ownerSortQuery), ENT_QUOTES); ?>">Current Owner</a></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $ticketStatus = strtolower((string) ($row['ticket_status'] ?? 'pending'));
                        if ($ticketStatus !== 'activated') {
                            $ticketStatus = 'pending';
                        }
                        $ownerName = trim((string) ($row['owner_name'] ?? ''));
                        $ownerDisplay = $ownerName !== '' ? $ownerName : 'In Chapter Pool';
                        ?>
                        <tr>
                            <td>
                                <div><?php echo htmlspecialchars((string) ($row['chapter_name'] ?? ''), ENT_QUOTES); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars((string) ($row['chapter_email'] ?? ''), ENT_QUOTES); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($row['ticket_number'] ?? ''), ENT_QUOTES); ?></td>
                            <td><span class="status-pill <?php echo htmlspecialchars($ticketStatus, ENT_QUOTES); ?>"><?php echo htmlspecialchars($ticketStatus, ENT_QUOTES); ?></span></td>
                            <td>
                                <div><?php echo htmlspecialchars($ownerDisplay, ENT_QUOTES); ?></div>
                                <?php if (!empty($row['owner_email'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars((string) $row['owner_email'], ENT_QUOTES); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Chapter list pagination" class="mt-3">
                    <ul class="pagination pagination-sm flex-wrap mb-0 gap-1">
                        <?php
                        $prevPage  = max(1, $page - 1);
                        $nextPage  = min($totalPages, $page + 1);
                        $prevQuery = $baseQuery; $prevQuery['page'] = $prevPage;
                        $nextQuery = $baseQuery; $nextQuery['page'] = $nextPage;
                        $firstQuery = $baseQuery; $firstQuery['page'] = 1;
                        $lastQuery  = $baseQuery; $lastQuery['page'] = $totalPages;

                        /* Build the set of page numbers to show with ellipsis gaps */
                        $window  = 2; // pages on each side of current
                        $visible = [];
                        for ($p = 1; $p <= $totalPages; $p++) {
                            if ($p === 1 || $p === $totalPages
                                || ($p >= $page - $window && $p <= $page + $window)) {
                                $visible[] = $p;
                            }
                        }
                        $visible = array_unique($visible);
                        sort($visible);
                        ?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($firstQuery), ENT_QUOTES); ?>" title="First">&laquo;</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES); ?>">Prev</a>
                        </li>
                        <?php $prev = null; foreach ($visible as $p):
                            if ($prev !== null && $p - $prev > 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif;
                            $pageQuery = $baseQuery; $pageQuery['page'] = $p; ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($pageQuery), ENT_QUOTES); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php $prev = $p; endforeach; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES); ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($lastQuery), ENT_QUOTES); ?>" title="Last">&raquo;</a>
                        </li>
                    </ul>
                    <div class="text-muted small mt-1">Page <?php echo $page; ?> of <?php echo $totalPages; ?> &mdash; <?php echo number_format($totalRows); ?> records</div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
