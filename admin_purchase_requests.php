<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

include 'db.php';
require_once __DIR__ . '/onsite_purchase_helper.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

onsite_ensure_schema($conn);

$message = '';
$messageType = 'success';
$paymentImagePaths = onsite_get_payment_image_paths($conn);
$paymentImageUrls = onsite_get_payment_image_urls($conn);

$allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
$selectedStatus = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'all';
}

$allowedPerPage = [10, 20, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 20);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$currentPage = (int) ($_GET['page'] ?? 1);
if ($currentPage <= 0) {
    $currentPage = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $approverId = auth_user_id();

    try {
        if ($action === 'save_payment_image') {
            if (!isset($_FILES['onsite_payment_image']) || !is_array($_FILES['onsite_payment_image'])) {
                throw new RuntimeException('Please choose a payment image to upload.');
            }

            onsite_replace_payment_images($conn, $_FILES['onsite_payment_image']);
            $paymentImagePaths = onsite_get_payment_image_paths($conn);
            $paymentImageUrls = onsite_get_payment_image_urls($conn);
            $message = 'Payment image replaced.';
            $messageType = 'success';
        } elseif ($action === 'add_payment_image') {
            if (!isset($_FILES['onsite_payment_image']) || !is_array($_FILES['onsite_payment_image'])) {
                throw new RuntimeException('Please choose a payment image to upload.');
            }

            onsite_add_payment_image($conn, $_FILES['onsite_payment_image']);
            $paymentImagePaths = onsite_get_payment_image_paths($conn);
            $paymentImageUrls = onsite_get_payment_image_urls($conn);
            $message = 'Additional payment image added.';
            $messageType = 'success';
        } elseif ($action === 'remove_single_payment_image') {
            $pathToRemove = trim((string) ($_POST['image_path'] ?? ''));
            if ($pathToRemove === '') {
                throw new RuntimeException('Invalid payment image selection.');
            }

            onsite_remove_payment_image($conn, $pathToRemove);
            $paymentImagePaths = onsite_get_payment_image_paths($conn);
            $paymentImageUrls = onsite_get_payment_image_urls($conn);
            $message = 'Payment image removed.';
            $messageType = 'success';
        } elseif ($action === 'remove_payment_image') {
            onsite_clear_payment_images($conn);
            $paymentImagePaths = [];
            $paymentImageUrls = [];
            $message = 'All payment images removed.';
            $messageType = 'success';
        } elseif ($action === 'approve_request') {
            $startingTicket = trim((string) ($_POST['starting_ticket_number'] ?? ''));
            $result = onsite_approve_purchase_request($conn, $appConfig, $requestId, $startingTicket, $approverId);
            if (!empty($result['mail_sent'])) {
                $message = 'Request #' . $requestId . ' approved and activation email sent via ' . (string) ($result['delivery_method'] ?? 'Gmail API') . '.';
                $messageType = 'success';
            } else {
                $message = 'Request #' . $requestId . ' approved, but sending the activation email failed: ' . (string) ($result['mail_error'] ?? 'Unknown mail error.');
                $messageType = 'warning';
            }
        } elseif ($action === 'reject_request') {
            onsite_reject_purchase_request($conn, $requestId, $approverId);
            $message = 'Request #' . $requestId . ' rejected.';
            $messageType = 'success';
        } else {
            throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'danger';
    }
}

$requests = [];
$requestQuery = $conn->query("SELECT r.*, u.username AS approver_username, u.name AS approver_name
    FROM ticket_purchase_requests r
    LEFT JOIN users u ON u.id = r.approved_by_admin_id
    ORDER BY r.created_at DESC, r.id DESC");
if ($requestQuery instanceof mysqli_result) {
    while ($row = $requestQuery->fetch_assoc()) {
        $requests[] = $row;
    }
    $requestQuery->close();
}

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($requests as $requestRow) {
    $status = strtolower((string) ($requestRow['status'] ?? 'pending'));
    if ($status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'rejected') {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }
}

$filteredRequests = [];
if ($selectedStatus === 'all') {
    $filteredRequests = $requests;
} else {
    foreach ($requests as $requestRow) {
        $status = strtolower((string) ($requestRow['status'] ?? 'pending'));
        if ($status === $selectedStatus) {
            $filteredRequests[] = $requestRow;
        }
    }
}

$totalFilteredRows = count($filteredRequests);
$totalPages = max(1, (int) ceil($totalFilteredRows / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;
$requestsPage = array_slice($filteredRequests, $offset, $perPage);

$paginationQuery = [
    'status' => $selectedStatus,
    'per_page' => $perPage,
];

$ticketPrefix = getTicketNumberPrefix($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onsite Purchase Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        :root {
            --primary: #283555;
            --accent: #ff6b35;
            --light-bg: #f8fafc;
            --line: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }
        body {
            background: var(--light-bg);
            color: var(--text-dark);
        }
        .dashboard-container { display: flex; min-height: 100vh; }
        main.main-content {
            flex: 1;
            padding: 2.2rem 1.25rem;
            margin-left: 280px;
        }
        .container-limit { max-width: 1200px; margin: 0 auto; }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 18px;
        }
        .header-row h1 {
            margin: 0;
            font-size: 1.7rem;
            font-weight: 700;
        }
        .header-row p { margin: 4px 0 0; color: var(--text-muted); }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
        }
        .stat-label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
        .stat-value { font-size: 28px; line-height: 1.1; font-weight: 700; color: var(--primary); }
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .panel-head {
            background: var(--primary);
            color: #fff;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
        }
        .panel-body { padding: 14px; }
        .table-wrap {
            overflow: auto;
            border: 1px solid #dbe4f0;
            border-radius: 12px;
            background: #fff;
            max-height: 68vh;
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            box-shadow: 0 1px 0 #dbe4f0;
        }
        .table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #475569;
            white-space: nowrap;
        }
        .table td {
            font-size: 13px;
            vertical-align: top;
            border-color: #e2e8f0;
        }
        .table tbody tr:nth-child(even) {
            background: #fcfdff;
        }
        .table tbody tr:hover {
            background: #f1f5f9;
        }
        .status-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 9px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fff7ed; color: #9a3412; }
        .status-approved { background: #ecfdf5; color: #166534; }
        .status-rejected { background: #fef2f2; color: #991b1b; }
        .inline-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 6px;
            min-width: 260px;
        }
        .inline-actions input {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            font-size: 13px;
        }
        .note { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .image-card {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .image-card img {
            display: block;
            width: 100%;
            height: 160px;
            object-fit: contain;
            background: #f8fafc;
        }
        .image-card .actions {
            padding: 8px;
            border-top: 1px solid #e2e8f0;
        }
        textarea.form-control {
            min-height: 130px;
            resize: vertical;
            font-size: 14px;
        }
        .filter-bar {
            display: flex;
            gap: 8px;
            align-items: end;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .request-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .quick-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .quick-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            padding: 6px 10px;
            border: 1px solid #d1d9e7;
            border-radius: 999px;
            color: #1e293b;
            background: #fff;
            font-size: 12px;
            font-weight: 600;
        }
        .quick-chip:hover {
            border-color: #94a3b8;
            color: #0f172a;
        }
        .quick-chip.active {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }
        .quick-chip .count {
            border-radius: 999px;
            padding: 1px 7px;
            background: rgba(15, 23, 42, 0.08);
            color: inherit;
            font-size: 11px;
            font-weight: 700;
        }
        .quick-chip.active .count {
            background: rgba(255, 255, 255, 0.2);
        }
        .filter-control {
            min-width: 160px;
        }
        .search-box {
            min-width: 280px;
            max-width: 420px;
            width: 100%;
        }
        .search-box input {
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 13px;
        }
        .search-box .input-group-text {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-right: 0;
        }
        .ticket-range {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            color: #334155;
        }
        .row-meta {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .confirm-modal {
            width: min(560px, 100%);
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
            overflow: hidden;
        }
        .confirm-head {
            background: #1e293b;
            color: #fff;
            padding: 11px 14px;
            font-size: 14px;
            font-weight: 600;
        }
        .confirm-body {
            padding: 14px;
            color: #1f2937;
            line-height: 1.45;
            font-size: 14px;
        }
        .confirm-actions {
            display: flex;
            justify-content: end;
            gap: 8px;
            padding: 0 14px 14px;
        }
        @media (max-width: 992px) {
            main.main-content {
                margin-left: 0;
                padding: 1.25rem .85rem;
            }
            .stats-grid { grid-template-columns: 1fr; }
            .search-box {
                min-width: 100%;
                max-width: 100%;
            }
            .inline-actions {
                grid-template-columns: 1fr;
                min-width: 200px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/admin_purchase_requests.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
        <div class="container-limit">
            <div class="header-row">
                <div>
                    <h1>Onsite Purchase Requests</h1>
                    <p>Approve or reject onsite purchase requests and assign sequential ticket ranges.</p>
                    <p class="note mb-0">Public form: <a href="/CPMraffle/buy_ticket.php" target="_blank" rel="noopener noreferrer">/CPMraffle/buy_ticket.php</a></p>
                </div>
                <div class="text-end">
                    <div class="note">Ticket prefix: <strong><?php echo htmlspecialchars($ticketPrefix, ENT_QUOTES); ?></strong></div>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo (int) $pendingCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Approved</div>
                    <div class="stat-value"><?php echo (int) $approvedCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Rejected</div>
                    <div class="stat-value"><?php echo (int) $rejectedCount; ?></div>
                </div>
            </div>

            <section class="panel">
                <div class="panel-head">Payment Options Image</div>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                        <input type="hidden" name="action" value="save_payment_image">
                        <div class="mb-2">
                            <input class="form-control form-control-sm" type="file" name="onsite_payment_image" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-arrows-rotate"></i> Replace Payment Images</button>
                    </form>

                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                        <input type="hidden" name="action" value="add_payment_image">
                        <div class="mb-2">
                            <input class="form-control form-control-sm" type="file" name="onsite_payment_image" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-plus"></i> Add Another Payment Image</button>
                    </form>

                    <button type="button" class="btn btn-outline-secondary btn-sm" id="togglePaymentImagesBtn">
                        <i class="fas fa-images"></i> Show/Hide Current Payment Images
                    </button>

                    <div class="mt-3" id="paymentImagesContainer" style="display:none;">
                        <?php if ($paymentImageUrls !== []): ?>
                            <p class="mb-1 text-muted small">Current public payment options images:</p>
                            <div class="image-grid">
                                <?php foreach ($paymentImageUrls as $idx => $imageUrl): ?>
                                    <?php $imagePath = isset($paymentImagePaths[$idx]) ? (string) $paymentImagePaths[$idx] : ''; ?>
                                    <div class="image-card">
                                        <img src="<?php echo htmlspecialchars((string) $imageUrl, ENT_QUOTES); ?>" alt="Payment Option <?php echo (int) ($idx + 1); ?>">
                                        <div class="actions">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="remove_single_payment_image">
                                                <input type="hidden" name="image_path" value="<?php echo htmlspecialchars($imagePath, ENT_QUOTES); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fas fa-trash"></i> Remove This Image</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <form method="POST" class="mt-2">
                                <input type="hidden" name="action" value="remove_payment_image">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Remove All Payment Images</button>
                            </form>
                        <?php else: ?>
                            <div class="note">No payment image uploaded yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">Purchase Request Queue</div>
                <div class="panel-body">
                    <div class="quick-filters">
                        <?php $allStatusQuery = http_build_query(['status' => 'all', 'per_page' => $perPage, 'page' => 1]); ?>
                        <?php $pendingStatusQuery = http_build_query(['status' => 'pending', 'per_page' => $perPage, 'page' => 1]); ?>
                        <?php $approvedStatusQuery = http_build_query(['status' => 'approved', 'per_page' => $perPage, 'page' => 1]); ?>
                        <?php $rejectedStatusQuery = http_build_query(['status' => 'rejected', 'per_page' => $perPage, 'page' => 1]); ?>

                        <a href="?<?php echo htmlspecialchars($allStatusQuery, ENT_QUOTES); ?>" class="quick-chip <?php echo $selectedStatus === 'all' ? 'active' : ''; ?>">
                            All
                            <span class="count"><?php echo (int) count($requests); ?></span>
                        </a>
                        <a href="?<?php echo htmlspecialchars($pendingStatusQuery, ENT_QUOTES); ?>" class="quick-chip <?php echo $selectedStatus === 'pending' ? 'active' : ''; ?>">
                            Pending
                            <span class="count"><?php echo (int) $pendingCount; ?></span>
                        </a>
                        <a href="?<?php echo htmlspecialchars($approvedStatusQuery, ENT_QUOTES); ?>" class="quick-chip <?php echo $selectedStatus === 'approved' ? 'active' : ''; ?>">
                            Approved
                            <span class="count"><?php echo (int) $approvedCount; ?></span>
                        </a>
                        <a href="?<?php echo htmlspecialchars($rejectedStatusQuery, ENT_QUOTES); ?>" class="quick-chip <?php echo $selectedStatus === 'rejected' ? 'active' : ''; ?>">
                            Rejected
                            <span class="count"><?php echo (int) $rejectedCount; ?></span>
                        </a>
                    </div>

                    <div class="request-toolbar">
                        <form method="GET" class="filter-bar mb-0">
                            <div class="filter-control">
                                <label class="form-label mb-1" for="status">Status</label>
                                <select class="form-select form-select-sm" name="status" id="status">
                                    <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="pending" <?php echo $selectedStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $selectedStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $selectedStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="filter-control">
                                <label class="form-label mb-1" for="per_page">Rows Per Page</label>
                                <select class="form-select form-select-sm" name="per_page" id="per_page">
                                    <?php foreach ($allowedPerPage as $option): ?>
                                        <option value="<?php echo (int) $option; ?>" <?php echo $perPage === (int) $option ? 'selected' : ''; ?>><?php echo (int) $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                            </div>
                        </form>

                        <div class="search-box">
                            <label class="form-label mb-1" for="requestSearch">Quick Search (current page)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-magnifying-glass"></i></span>
                                <input type="text" class="form-control" id="requestSearch" placeholder="Search buyer, email, chapter, contact, status, or range">
                            </div>
                        </div>
                    </div>

                    <div class="row-meta">
                        Visible rows: <strong id="visibleRowsCount"><?php echo (int) count($requestsPage); ?></strong>
                    </div>

                    <div class="table-wrap">
                        <table class="table table-bordered align-middle" id="requestsTable">
                            <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Buyer</th>
                                <th>Contact</th>
                                <th>Quantity</th>
                                <th>Proof</th>
                                <th>Status</th>
                                <th>Assigned Range</th>
                                <th>Approver</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($requestsPage === []): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No purchase requests found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($requestsPage as $request): ?>
                                    <?php
                                    $status = strtolower((string) ($request['status'] ?? 'pending'));
                                    $statusClass = $status === 'approved' ? 'status-approved' : ($status === 'rejected' ? 'status-rejected' : 'status-pending');
                                    $approverName = trim((string) ($request['approver_name'] ?? ''));
                                    if ($approverName === '') {
                                        $approverName = trim((string) ($request['approver_username'] ?? ''));
                                    }
                                    ?>
                                    <tr data-request-row="1">
                                        <td><?php echo (int) ($request['id'] ?? 0); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) ($request['name'] ?? ''), ENT_QUOTES); ?></strong><br>
                                            <span class="text-muted small"><?php echo htmlspecialchars((string) ($request['email'] ?? ''), ENT_QUOTES); ?></span><br>
                                            <span class="text-muted small">Chapter: <?php echo htmlspecialchars((string) ($request['chapter'] ?? ''), ENT_QUOTES); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string) ($request['contact_number'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo (int) ($request['ticket_quantity'] ?? 0); ?></td>
                                        <td>
                                            <?php if (!empty($request['proof_of_payment_url'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars((string) $request['proof_of_payment_url'], ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">
                                                    <i class="fas fa-file-invoice"></i> View Proof
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status-pill <?php echo htmlspecialchars($statusClass, ENT_QUOTES); ?>"><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES); ?></span></td>
                                        <td><span class="ticket-range"><?php echo htmlspecialchars((string) ($request['assigned_tickets_range'] ?? '-'), ENT_QUOTES); ?></span></td>
                                        <td><?php echo htmlspecialchars($approverName !== '' ? $approverName : '-', ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <?php if ($status === 'pending'): ?>
                                                <form method="POST" class="inline-actions mb-1 approve-form"
                                                    data-request-id="<?php echo (int) ($request['id'] ?? 0); ?>"
                                                    data-buyer-name="<?php echo htmlspecialchars((string) ($request['name'] ?? ''), ENT_QUOTES); ?>"
                                                    data-quantity="<?php echo (int) ($request['ticket_quantity'] ?? 0); ?>">
                                                    <input type="hidden" name="action" value="approve_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                    <input type="text" name="starting_ticket_number" placeholder="Start no. e.g. 12000" required>
                                                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" class="mt-1">
                                                    <input type="hidden" name="action" value="reject_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">No actions available</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-light border mt-2 mb-0 d-none" id="clientNoResults">
                        No rows on this page match your search.
                    </div>
                    <div class="pagination-wrap">
                        <div class="note mb-0">
                            Showing <?php echo $totalFilteredRows > 0 ? (int) ($offset + 1) : 0; ?> to <?php echo (int) min($offset + count($requestsPage), $totalFilteredRows); ?> of <?php echo (int) $totalFilteredRows; ?> request(s).
                        </div>
                        <nav aria-label="Request pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $prevPage = max(1, $currentPage - 1);
                                $nextPage = min($totalPages, $currentPage + 1);
                                $prevQuery = http_build_query(array_merge($paginationQuery, ['page' => $prevPage]));
                                $nextQuery = http_build_query(array_merge($paginationQuery, ['page' => $nextPage]));
                                ?>
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo htmlspecialchars($prevQuery, ENT_QUOTES); ?>">Previous</a>
                                </li>
                                <li class="page-item disabled"><span class="page-link">Page <?php echo (int) $currentPage; ?> of <?php echo (int) $totalPages; ?></span></li>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo htmlspecialchars($nextQuery, ENT_QUOTES); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <div class="note">Approvals assign tickets sequentially starting from the number you provide, for example <strong>12000</strong> assigns <?php echo htmlspecialchars($ticketPrefix, ENT_QUOTES); ?>-12000 onward.</div>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="modal-overlay" id="approveModal" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="approveModalTitle">
        <div class="confirm-head" id="approveModalTitle">Confirm Ticket Assignment Approval</div>
        <div class="confirm-body">
            <p class="mb-2">You are about to approve request <strong>#<span id="confirmRequestId">0</span></strong> for <strong><span id="confirmBuyerName">Buyer</span></strong>.</p>
            <p class="mb-2">This will assign <strong><span id="confirmQuantity">0</span></strong> sequential ticket(s) starting at <strong><span id="confirmStart">0</span></strong>.</p>
            <p class="mb-0 text-danger"><strong>Important:</strong> This action creates buyer assignments and triggers the activation email flow.</p>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelApproveBtn">Cancel</button>
            <button type="button" class="btn btn-success btn-sm" id="confirmApproveBtn"><i class="fas fa-check"></i> Confirm Approval</button>
        </div>
    </div>
</div>

<script>
(function () {
    const togglePaymentImagesBtn = document.getElementById('togglePaymentImagesBtn');
    const paymentImagesContainer = document.getElementById('paymentImagesContainer');
    if (togglePaymentImagesBtn && paymentImagesContainer) {
        togglePaymentImagesBtn.addEventListener('click', function () {
            paymentImagesContainer.style.display = paymentImagesContainer.style.display === 'none' ? 'block' : 'none';
        });
    }

    const modal = document.getElementById('approveModal');
    const confirmRequestId = document.getElementById('confirmRequestId');
    const confirmBuyerName = document.getElementById('confirmBuyerName');
    const confirmQuantity = document.getElementById('confirmQuantity');
    const confirmStart = document.getElementById('confirmStart');
    const cancelBtn = document.getElementById('cancelApproveBtn');
    const confirmBtn = document.getElementById('confirmApproveBtn');
    let pendingForm = null;

    function closeModal() {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        pendingForm = null;
    }

    document.querySelectorAll('.approve-form').forEach((form) => {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const startInput = form.querySelector('input[name="starting_ticket_number"]');
            if (!startInput || startInput.value.trim() === '') {
                if (startInput) {
                    startInput.reportValidity();
                    startInput.focus();
                }
                return;
            }

            pendingForm = form;
            confirmRequestId.textContent = form.getAttribute('data-request-id') || '0';
            confirmBuyerName.textContent = form.getAttribute('data-buyer-name') || 'Buyer';
            confirmQuantity.textContent = form.getAttribute('data-quantity') || '0';
            confirmStart.textContent = startInput.value.trim();

            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    confirmBtn.addEventListener('click', function () {
        if (!pendingForm) {
            closeModal();
            return;
        }

        const submittingForm = pendingForm;
        closeModal();
        submittingForm.submit();
    });

    const requestSearch = document.getElementById('requestSearch');
    const requestRows = Array.from(document.querySelectorAll('#requestsTable tbody tr[data-request-row="1"]'));
    const noResults = document.getElementById('clientNoResults');
    const visibleRowsCount = document.getElementById('visibleRowsCount');

    function runClientSearch() {
        if (!requestSearch || requestRows.length === 0) {
            return;
        }

        const term = requestSearch.value.trim().toLowerCase();
        let visible = 0;

        requestRows.forEach((row) => {
            const haystack = row.textContent.toLowerCase();
            const matched = term === '' || haystack.indexOf(term) !== -1;
            row.style.display = matched ? '' : 'none';
            if (matched) {
                visible += 1;
            }
        });

        if (visibleRowsCount) {
            visibleRowsCount.textContent = String(visible);
        }

        if (noResults) {
            noResults.classList.toggle('d-none', visible > 0 || term === '');
        }
    }

    if (requestSearch) {
        requestSearch.addEventListener('input', runClientSearch);
    }
})();
</script>
</body>
</html>
