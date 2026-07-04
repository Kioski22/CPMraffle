<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

include 'db.php';
require_once 'buyer_schema.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

ensureBuyerSchema($conn);

$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$countSql = "SELECT COUNT(*) AS total
    FROM tickets t
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    WHERE ta.ticket_number IS NULL
      AND LOWER(t.status) = 'pending'";

$listSql = "SELECT t.ticket_number, t.status, t.created_at
    FROM tickets t
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    WHERE ta.ticket_number IS NULL
      AND LOWER(t.status) = 'pending'";

$params = [];
$types = '';

if ($search !== '') {
    $countSql .= ' AND t.ticket_number LIKE ?';
    $listSql .= ' AND t.ticket_number LIKE ?';
    $term = '%' . $search . '%';
    $params[] = $term;
    $types .= 's';
}

$totalAvailable = 0;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalAvailable = (int) (($countResult->fetch_assoc()['total'] ?? 0));
    $countStmt->close();
}

$totalPages = max(1, (int) ceil($totalAvailable / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$availableTickets = [];
$listSql .= ' ORDER BY t.ticket_number ASC LIMIT ? OFFSET ?';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$listTypes = $types . 'ii';

$listStmt = $conn->prepare($listSql);
if ($listStmt) {
    $bindValues = [$listTypes];
    foreach ($listParams as $index => $_value) {
        $bindValues[] = &$listParams[$index];
    }
    call_user_func_array([$listStmt, 'bind_param'], $bindValues);
    $listStmt->execute();
    $listResult = $listStmt->get_result();
    while ($row = $listResult->fetch_assoc()) {
        $availableTickets[] = $row;
    }
    $listStmt->close();
}

$listStart = $totalAvailable === 0 ? 0 : $offset + 1;
$listEnd = $totalAvailable === 0 ? 0 : min($offset + count($availableTickets), $totalAvailable);
$paginationWindow = 2;
$pageStart = max(1, $page - $paginationWindow);
$pageEnd = min($totalPages, $page + $paginationWindow);

function pool_ticket_page_link(int $pageNumber, string $search): string
{
    $query = ['page' => $pageNumber];
    if ($search !== '') {
        $query['search'] = $search;
    }

    return '/CPMraffle/pool_tickets.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Pool</title>
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
            padding: 10px 24px;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
            border: none;
            font-family: 'Poppins', sans-serif;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
        .table {
            margin-bottom: 0;
        }
        .table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            font-weight: 700;
            border: none;
            padding: 16px;
        }
        .table td {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            color: var(--dark);
        }
        .table tbody tr:hover {
            background: var(--light);
        }
        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 24px 22px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .pagination-summary {
            color: var(--gray);
            font-weight: 500;
        }
        .pagination {
            margin: 0;
        }
        .page-link {
            color: var(--primary);
            border-color: var(--border-color);
            font-weight: 600;
            padding: 10px 14px;
        }
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-color: var(--primary);
        }
        .page-item.disabled .page-link {
            color: #9aa6b2;
        }
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-family: 'Poppins', sans-serif;
            transition: all var(--transition);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.1);
        }
        h2 {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 24px;
            font-size: 2rem;
        }
        .badge {
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .pool-summary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 12px;
            padding: 32px 24px;
            text-align: center;
            margin-bottom: 24px;
            box-shadow: 0 8px 30px rgba(40, 53, 85, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .pool-summary h3 {
            color: white;
            margin: 0;
            font-size: 2.8rem;
            font-weight: 700;
        }
        .pool-summary p {
            margin: 10px 0 0 0;
            opacity: 0.95;
            font-weight: 500;
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

            .btn {
                padding: 8px 16px;
                font-size: 0.95rem;
            }
            h2 {
                font-size: 1.6rem;
            }
            .pool-summary h3 {
                font-size: 2rem;
            }
            .pagination-wrap {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/pool_tickets.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Available Ticket Pool</h2>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="pool-summary">
            <h3><?php echo number_format($totalAvailable); ?></h3>
            <p>Total tickets available for assignment</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search ticket number" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary">Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white">
            <strong>List of Available Tickets</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 80px;">#</th>
                        <th>Ticket Number</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($availableTickets)): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No available tickets in the pool.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($availableTickets as $index => $ticket): ?>
                        <tr>
                            <td><?php echo $offset + $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($ticket['ticket_number'], ENT_QUOTES); ?></td>
                            <td><span class="badge text-bg-success">available</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalAvailable > 0): ?>
            <div class="pagination-wrap">
                <div class="pagination-summary">
                    Showing <?php echo number_format($listStart); ?>-<?php echo number_format($listEnd); ?> of <?php echo number_format($totalAvailable); ?> tickets
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Ticket pool pagination">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $page <= 1 ? '#' : htmlspecialchars(pool_ticket_page_link($page - 1, $search), ENT_QUOTES); ?>" <?php echo $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Previous</a>
                            </li>
                            <?php if ($pageStart > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo htmlspecialchars(pool_ticket_page_link(1, $search), ENT_QUOTES); ?>">1</a>
                                </li>
                                <?php if ($pageStart > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                                <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars(pool_ticket_page_link($pageNumber, $search), ENT_QUOTES); ?>"><?php echo $pageNumber; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($pageEnd < $totalPages): ?>
                                <?php if ($pageEnd < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo htmlspecialchars(pool_ticket_page_link($totalPages, $search), ENT_QUOTES); ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php endif; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : htmlspecialchars(pool_ticket_page_link($page + 1, $search), ENT_QUOTES); ?>" <?php echo $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
    </main>
</div>
</body>
</html>



