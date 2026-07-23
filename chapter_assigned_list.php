<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['chapter']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/chapter_portal_helper.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);

$chapterId = auth_user_id();
try {
    $chapterUser = chapter_portal_require_user($conn, $chapterId);
} catch (Throwable $exception) {
    http_response_code(403);
    exit($exception->getMessage());
}

$chapterName = chapter_portal_chapter_name($chapterUser);

function chapter_assigned_bind_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $bindValues = [];
    $bindValues[] = $types;
    foreach ($values as $index => $_value) {
        $bindValues[] = &$values[$index];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'activated'], true)) {
    $statusFilter = 'all';
}

$remarksOptions = [
    'all' => '',
    'paid' => 'Paid',
    'fully_paid' => 'Fully Paid',
    'partially_paid' => 'Partially Paid',
    'pending' => 'Pending',
    'not_paid' => 'Not Paid',
];
$remarksFilter = strtolower(trim((string) ($_GET['remarks'] ?? 'all')));
if (!array_key_exists($remarksFilter, $remarksOptions)) {
    $remarksFilter = 'all';
}

$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) {
    $page = 1;
}

$pageSize = 25;
$whereParts = ['t.assigned_chapter_id = ?'];
$types = 'i';
$params = [$chapterId];

if ($search !== '') {
    $whereParts[] = '(ta.ticket_number LIKE ? OR b.full_name LIKE ? OR b.email LIKE ? OR b.contact LIKE ?)';
    $searchLike = '%' . $search . '%';
    $types .= 'ssss';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}

if ($statusFilter === 'activated') {
    $whereParts[] = '(LOWER(COALESCE(t.status, "pending")) = "activated" OR b.activation_completed_at IS NOT NULL)';
} elseif ($statusFilter === 'pending') {
    $whereParts[] = '(LOWER(COALESCE(t.status, "pending")) <> "activated" AND b.activation_completed_at IS NULL)';
}

if ($remarksFilter !== 'all') {
    $whereParts[] = 'LOWER(COALESCE(NULLIF(TRIM(b.remarks), ""), "Pending")) = ?';
    $types .= 's';
    $params[] = strtolower((string) $remarksOptions[$remarksFilter]);
}

$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

$totalRows = 0;
$countSql = 'SELECT COUNT(*) AS total_count
    FROM ticket_assignments ta
    INNER JOIN buyers b ON b.id = ta.buyer_id
    INNER JOIN tickets t ON t.ticket_number = ta.ticket_number' . $whereSql;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $countValues = $params;
    if (chapter_assigned_bind_params($countStmt, $types, $countValues)) {
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

$assignedRows = [];
$stmt = $conn->prepare('SELECT
        ta.ticket_number,
        ta.assigned_at,
        b.full_name,
        b.email,
        b.contact,
        b.buyer_type,
    b.remarks,
        b.activation_completed_at,
        LOWER(COALESCE(t.status, "pending")) AS ticket_status
    FROM ticket_assignments ta
    INNER JOIN buyers b ON b.id = ta.buyer_id
    INNER JOIN tickets t ON t.ticket_number = ta.ticket_number
    ' . $whereSql . '
    ORDER BY ta.assigned_at DESC, ta.id DESC
    LIMIT ?, ?');

if ($stmt) {
    $queryTypes = $types . 'ii';
    $queryValues = $params;
    $queryValues[] = $offset;
    $queryValues[] = $pageSize;
    if (chapter_assigned_bind_params($stmt, $queryTypes, $queryValues)) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $assignedRows[] = $row;
        }
    }
    $stmt->close();
}

$fromRow = $totalRows === 0 ? 0 : ($offset + 1);
$toRow = min($totalRows, $offset + $pageSize);

$baseQuery = [
    'q' => $search,
    'status' => $statusFilter,
    'remarks' => $remarksFilter,
];
?>
<!DOCTYPE html>
<html lang="en">
<?php chapter_portal_render_head('Chapter Assigned List'); ?>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/chapter_assigned_list.php', false); ?>
    <div class="chapter-shell">
        <section class="chapter-hero">
            <div class="chapter-eyebrow"><i class="fas fa-list-check"></i> Assigned Buyers</div>
            <h1>Search your assigned buyers and quickly see who is pending or already activated.</h1>
            <p class="mb-0">Use the large search tools below to look up a ticket number, buyer name, email, or contact number.</p>
            <div class="chapter-meta">
                <div class="chapter-meta-chip"><i class="fas fa-building"></i> Chapter <strong><?php echo htmlspecialchars($chapterName, ENT_QUOTES); ?></strong></div>
                <div class="chapter-meta-chip"><i class="fas fa-users"></i> Total records <strong><?php echo htmlspecialchars((string) $totalRows, ENT_QUOTES); ?></strong></div>
            </div>
        </section>

        <div class="chapter-panel">
            <form class="row g-3 mb-3" method="GET" action="">
                <div class="col-md-4">
                    <label class="chapter-form-label" for="q">Search</label>
                    <input id="q" name="q" type="text" class="form-control chapter-input" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>" placeholder="Ticket, name, email, contact">
                </div>
                <div class="col-md-3">
                    <label class="chapter-form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select chapter-input">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="activated" <?php echo $statusFilter === 'activated' ? 'selected' : ''; ?>>Activated</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="chapter-form-label" for="remarks">Remarks</label>
                    <select id="remarks" name="remarks" class="form-select chapter-input">
                        <option value="all" <?php echo $remarksFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $remarksFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="not_paid" <?php echo $remarksFilter === 'not_paid' ? 'selected' : ''; ?>>Not Paid</option>
                        <option value="partially_paid" <?php echo $remarksFilter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="paid" <?php echo $remarksFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="fully_paid" <?php echo $remarksFilter === 'fully_paid' ? 'selected' : ''; ?>>Fully Paid</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-3 flex-wrap">
                    <button type="submit" class="chapter-primary-button"><i class="fas fa-magnifying-glass"></i> Apply</button>
                    <a class="chapter-secondary-button" href="/CPMraffle/chapter_assigned_list.php"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </form>

            <p class="chapter-section-intro mb-3">
                Showing <?php echo htmlspecialchars((string) $fromRow, ENT_QUOTES); ?>-<?php echo htmlspecialchars((string) $toRow, ENT_QUOTES); ?> of <?php echo htmlspecialchars((string) $totalRows, ENT_QUOTES); ?> records.
            </p>

            <?php if ($assignedRows === []): ?>
                <div class="alert alert-warning mb-0">No buyers have been assigned yet from this chapter.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table chapter-table align-middle">
                        <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Buyer Type</th>
                            <th>Remarks</th>
                            <th>Assigned At</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignedRows as $row): ?>
                            <?php $isActivated = strtolower((string) ($row['ticket_status'] ?? 'pending')) === 'activated' || !empty($row['activation_completed_at']); ?>
                            <?php $remarksValue = trim((string) ($row['remarks'] ?? '')); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($row['ticket_number'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['contact'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['buyer_type'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars($remarksValue !== '' ? $remarksValue : 'Pending', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['assigned_at'] ?? ''), ENT_QUOTES); ?></td>
                                <td>
                                    <?php if ($isActivated): ?>
                                        <span class="chapter-status-badge chapter-status-active">Active</span>
                                    <?php else: ?>
                                        <span class="chapter-status-badge chapter-status-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-grid gap-3 mt-3 d-md-none">
                    <?php foreach ($assignedRows as $row): ?>
                        <?php $isActivated = strtolower((string) ($row['ticket_status'] ?? 'pending')) === 'activated' || !empty($row['activation_completed_at']); ?>
                        <?php $remarksValue = trim((string) ($row['remarks'] ?? '')); ?>
                        <div class="chapter-mobile-record">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <div class="chapter-mobile-record-kicker">Ticket #</div>
                                    <div class="chapter-mobile-record-title"><?php echo htmlspecialchars((string) ($row['ticket_number'] ?? ''), ENT_QUOTES); ?></div>
                                </div>
                                <?php if ($isActivated): ?>
                                    <span class="chapter-status-badge chapter-status-active">Active</span>
                                <?php else: ?>
                                    <span class="chapter-status-badge chapter-status-pending">Pending</span>
                                <?php endif; ?>
                            </div>
                            <div class="chapter-mobile-record-row"><span>Full Name</span><strong><?php echo htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Email</span><strong><?php echo htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Contact</span><strong><?php echo htmlspecialchars((string) ($row['contact'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Buyer Type</span><strong><?php echo htmlspecialchars((string) ($row['buyer_type'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Remarks</span><strong><?php echo htmlspecialchars($remarksValue !== '' ? $remarksValue : 'Pending', ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Assigned At</span><strong><?php echo htmlspecialchars((string) ($row['assigned_at'] ?? ''), ENT_QUOTES); ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Assigned list pagination" class="mt-3">
                        <ul class="pagination mb-0">
                            <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $prevQuery = $baseQuery;
                            $prevQuery['page'] = $prevPage;
                            $nextQuery = $baseQuery;
                            $nextQuery['page'] = $nextPage;
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES); ?>">Previous</a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php $pageQuery = $baseQuery; $pageQuery['page'] = $p; ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($pageQuery), ENT_QUOTES); ?>"><?php echo htmlspecialchars((string) $p, ENT_QUOTES); ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
