<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['chapter']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/chapter_portal_helper.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);

$chapterUserId = auth_user_id();
try {
    $chapterUser = chapter_portal_require_user($conn, $chapterUserId);
} catch (Throwable $exception) {
    http_response_code(403);
    exit($exception->getMessage());
}

$chapterName = chapter_portal_chapter_name($chapterUser);
$metrics = chapter_portal_metrics($conn, $chapterUserId);

$allocations = [];
$allocationStmt = $conn->prepare('SELECT start_ticket, end_ticket, allocated_at FROM chapter_allocations WHERE chapter_id = ? ORDER BY allocated_at DESC, id DESC');
if ($allocationStmt) {
    $allocationStmt->bind_param('i', $chapterUserId);
    $allocationStmt->execute();
    $allocationResult = $allocationStmt->get_result();
    while ($allocationResult && ($row = $allocationResult->fetch_assoc())) {
        $allocations[] = $row;
    }
    $allocationStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<?php chapter_portal_render_head('Chapter Dashboard - Ticket Management System'); ?>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/chapter_dashboard.php', false); ?>
    <div class="page-shell">
        <div class="hero">
            <div class="badge text-bg-light border mb-3 px-3 py-2">Chapter President Portal</div>
            <h1>Welcome back. Everything your chapter needs is here in one clear dashboard.</h1>
            <p class="hero-lead mb-3">Assign individual tickets and review buyer activation status using larger controls and easier-to-read tables.</p>
            <div class="d-flex flex-wrap gap-3">
                <div class="info-strip py-3 px-4">
                    <strong>Signed in as:</strong>
                    <?php echo htmlspecialchars((string) ($chapterUser['username'] ?? ''), ENT_QUOTES); ?>
                </div>
                <div class="info-strip py-3 px-4">
                    <strong>Chapter:</strong>
                    <?php echo htmlspecialchars($chapterName !== '' ? $chapterName : 'Not set yet', ENT_QUOTES); ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="metric-card">
                    <div class="metric-label">Tickets Allocated</div>
                    <div class="metric-value"><?php echo htmlspecialchars((string) ($metrics['total_allocated'] ?? 0), ENT_QUOTES); ?></div>
                    <div class="metric-description">All tickets currently reserved for your chapter.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card is-accent">
                    <div class="metric-label">Tickets Assigned</div>
                    <div class="metric-value"><?php echo htmlspecialchars((string) ($metrics['total_assigned'] ?? 0), ENT_QUOTES); ?></div>
                    <div class="metric-description">Buyers already matched to tickets from your pool.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card is-light">
                    <div class="metric-label">Tickets Remaining</div>
                    <div class="metric-value"><?php echo htmlspecialchars((string) ($metrics['total_remaining'] ?? 0), ENT_QUOTES); ?></div>
                    <div class="metric-description">Tickets still available for new assignments.</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="action-card">
                    <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                    <h2>Single Ticket Assignment</h2>
                    <p>Use this for one buyer at a time. The system will automatically pick the next available ticket in your chapter allocation.</p>
                    <a class="btn btn-primary btn-action" href="/CPMraffle/chapter_buyer_assignment.php">
                        <span>
                            Open Assignment Page
                            <small>Large form fields with clear labels</small>
                        </span>
                    </a>
                </div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                    <h2 class="section-title mb-1">Chapter Allocations</h2>
                    <p class="section-intro mb-0">These ticket ranges are currently assigned to your chapter.</p>
                </div>
                <div class="info-strip py-3 px-4">
                    <strong>Total ranges:</strong>
                    <?php echo htmlspecialchars((string) count($allocations), ENT_QUOTES); ?>
                </div>
            </div>

            <?php if ($allocations === []): ?>
                <div class="alert alert-warning mb-0">No ticket allocations have been recorded for this chapter yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Start Ticket</th>
                            <th>End Ticket</th>
                            <th>Allocated At</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allocations as $allocation): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) ($allocation['start_ticket'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($allocation['end_ticket'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($allocation['allocated_at'] ?? ''), ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-grid gap-3 mt-3 d-md-none">
                    <?php foreach ($allocations as $allocation): ?>
                        <div class="chapter-mobile-record">
                            <div class="chapter-mobile-record-row"><span>Start Ticket</span><strong><?php echo htmlspecialchars((string) ($allocation['start_ticket'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>End Ticket</span><strong><?php echo htmlspecialchars((string) ($allocation['end_ticket'] ?? ''), ENT_QUOTES); ?></strong></div>
                            <div class="chapter-mobile-record-row"><span>Allocated At</span><strong><?php echo htmlspecialchars((string) ($allocation['allocated_at'] ?? ''), ENT_QUOTES); ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-4">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                <div>
                    <h2 class="section-title mb-1">Assigned Buyers</h2>
                    <p class="section-intro mb-0">Review all buyers from your chapter and quickly check whether their tickets are still pending or already activated.</p>
                </div>
                <a class="btn btn-outline-secondary" href="/CPMraffle/chapter_assigned_list.php">Open Assigned List</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
