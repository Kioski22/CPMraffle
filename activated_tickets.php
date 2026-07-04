<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'cpmadmin']);
require_once __DIR__ . '/dashboard_sidebar_helper.php';
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

// Get the user ID from the session
$user_id = $_SESSION['id'];

// Fetch user profile picture from the database
$profile_picture = "https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png"; // Default image if no profile picture found
$user_query = "SELECT profile_picture FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result && mysqli_num_rows($user_result) > 0) {
  $user_data = mysqli_fetch_assoc($user_result);
  if (!empty($user_data['profile_picture'])) {
    $profile_picture = "uploads/profile_pictures/" . $user_data['profile_picture'];
  }
}

// Query to get the total number of activated tickets
$queryTotal = "SELECT COUNT(*) as total FROM tickets WHERE LOWER(status) = 'activated'";
$resultTotal = mysqli_query($conn, $queryTotal);
$totalActivated = 0;
if ($resultTotal && mysqli_num_rows($resultTotal) > 0) {
  $row = mysqli_fetch_assoc($resultTotal);
  $totalActivated = $row['total'];
}

// Query to group activated tickets by month (January till October)
$ticketCounts = [];
$query = "SELECT DATE_FORMAT(created_at, '%M') as month, COUNT(*) as count 
          FROM tickets 
          WHERE LOWER(status) = 'activated' AND MONTH(created_at) <= 10 
          GROUP BY MONTH(created_at) 
          ORDER BY MONTH(created_at)";
$result = mysqli_query($conn, $query);
if ($result) {
  while ($row = mysqli_fetch_assoc($result)) {
    $ticketCounts[] = $row;
  }
}
$chartLabels = array_column($ticketCounts, 'month');
$chartData = array_column($ticketCounts, 'count');

// Latest activated ticket entries for on-page list view
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'activated')));
if (!in_array($statusFilter, ['all', 'activated', 'pending'], true)) {
  $statusFilter = 'activated';
}

$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) {
  $page = 1;
}

$pageSize = 25;
$whereParts = [];
$types = '';
$params = [];

if ($statusFilter !== 'all') {
  $whereParts[] = 'LOWER(COALESCE(a.status, "")) = ?';
  $types .= 's';
  $params[] = $statusFilter;
}

if ($searchQuery !== '') {
  $whereParts[] = '(a.ticket_number LIKE ? OR a.full_name LIKE ? OR t.email LIKE ? OR t.contact_number LIKE ?)';
  $searchLike = '%' . $searchQuery . '%';
  $types .= 'ssss';
  $params[] = $searchLike;
  $params[] = $searchLike;
  $params[] = $searchLike;
  $params[] = $searchLike;
}

$whereSql = $whereParts === [] ? '' : (' WHERE ' . implode(' AND ', $whereParts));

function activated_tickets_bind_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
  if ($types === '') {
    return true;
  }

  $bind = [];
  $bind[] = $types;
  foreach ($values as $index => $_value) {
    $bind[] = &$values[$index];
  }

  return call_user_func_array([$stmt, 'bind_param'], $bind);
}

$totalRows = 0;
$countSql = 'SELECT COUNT(*) AS total_count
    FROM ticket_activations a
    LEFT JOIN tickets t ON t.ticket_number = a.ticket_number' . $whereSql;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
  $countValues = $params;
  if (activated_tickets_bind_params($countStmt, $types, $countValues)) {
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

$activatedTicketRows = [];
$activatedListSql = 'SELECT
            a.ticket_number,
            a.full_name,
            t.email,
            t.contact_number,
            a.activation_datetime,
            a.status
        FROM ticket_activations a
        LEFT JOIN tickets t ON t.ticket_number = a.ticket_number
        ' . $whereSql . '
        ORDER BY a.activation_datetime DESC
        LIMIT ?, ?';
$activatedListStmt = $conn->prepare($activatedListSql);
if ($activatedListStmt) {
  $queryTypes = $types . 'ii';
  $queryValues = $params;
  $queryValues[] = $offset;
  $queryValues[] = $pageSize;
  if (activated_tickets_bind_params($activatedListStmt, $queryTypes, $queryValues)) {
    $activatedListStmt->execute();
    $activatedListResult = $activatedListStmt->get_result();
    while ($activatedListResult && ($row = $activatedListResult->fetch_assoc())) {
      $activatedTicketRows[] = $row;
    }
  }
  $activatedListStmt->close();
}

$fromRow = $totalRows === 0 ? 0 : ($offset + 1);
$toRow = min($totalRows, $offset + $pageSize);
$baseQuery = [
  'q' => $searchQuery,
  'status' => $statusFilter,
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
   <link rel="icon" type="image/x-icon" href="img/favicon.ico">
  <style>
    /* Global Reset and CSS Variables */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
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
      --bg: #f4f6f9;
      --success: #27ae60;
      --warning: #f39c12;
      --danger: #e74c3c;
      --border-color: #e8eef5;
      --transition-speed: 0.3s;
    }

    html,
        body {
            background: linear-gradient(rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0.85)),
        url(https://scontent.fmnl4-4.fna.fbcdn.net/v/t39.30808-6/476909789_122193973430148434_6008072516477437592_n.jpg?_nc_cat=100&ccb=1-7&_nc_sid=127cfc&_nc_eui2=AeFsvBVC1ThndkRAwcH7zQGwEktyJHj32KcSS3IkePfYpw5lHdjfKKRPrEJjLJAqDtVF7HMg9JUOL2VH7SZRM3xB&_nc_ohc=GgqG9UeHWTQQ7kNvwEFCQQ0&_nc_oc=Adm3qKArdu10juPhyCuPAKNIp5c3y4NTN1Wyr__c_6kMUVRkyt0MTeTvBp80EkCUDzM&_nc_zt=23&_nc_ht=scontent.fmnl4-4.fna&_nc_gid=I56dKqWKzSQOBN8ZFHKCuw&oh=00_AfFij6rjTWEc1GpBRBUXyZeCTrOaSO8U8cV67xAxAgLyqw&oe=67F90206);
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            color: var(--dark);

        }

    /* Header & Profile */
    header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      padding: 1rem 2rem;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      box-shadow: 0 4px 15px rgba(40, 53, 85, 0.15);
      position: relative;
      z-index: 1;
    }

    .profile {
  position: relative;
  cursor: pointer;
  display: inline-block;
}

/* Profile Image Styling */
.profile img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  transition: transform var(--transition-speed) ease, box-shadow 0.3s ease;
  object-fit: cover;
  border: 2px solid white; /* Add white border around the image */
}

.profile img:hover {
  transform: scale(1.1);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Profile Dropdown Menu */
.profile-dropdown {
  position: absolute;
  right: 0;
  top: 55px;
  background: var(--white);
  border: 1px solid #ddd;
  border-radius: 8px;
  display: none;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  z-index: 100;
  min-width: 150px;
  overflow: hidden;
}

/* Profile Dropdown Links */
.profile-dropdown a {
  display: block;
  padding: 12px 20px;
  color: var(--dark);
  text-decoration: none;
  font-size: 14px;
  transition: background var(--transition-speed) ease, padding-left 0.3s;
  border-bottom: 1px solid #eee;
}

.profile-dropdown a:hover {
  background: #f0f0f0;
  padding-left: 25px;
}

/* Profile Dropdown Visibility */
.profile-dropdown.show {
  display: block;
}
    /* Dashboard Layout */
    .dashboard-container {
      display: flex;
      min-height: calc(100vh - 70px);
    }

    /* Sidebar - keep background image intact */
    aside.sidebar {
      width: 250px;
      height: 100vh;
      background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
      color: var(--light);
      padding: 22px 20px 30px 20px;
      position: fixed;
      z-index: 2;
      bottom: 0;
      left: 0;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
    }

    aside.sidebar .sidebar-header {
      text-align: center;
      margin-bottom: 10px;
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

    aside.sidebar nav ul li a:hover {
      background: rgba(255, 107, 53, 0.2);
      padding-left: 15px;
      border-left: 3px solid var(--accent);
    }

    /* Main Content Area */
    .main-content {
      margin-left: 280px;
      padding: 2rem;
      width: calc(100% - 280px);
    }

    /* Ticket Card */
    .cards {
            display: flex;
            justify-content: center; /* Center the card */
            margin-bottom: 2rem;
        }
        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            width: 300px; /* Set a fixed width for the card */
            border: 1px solid var(--border-color);
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(40, 53, 85, 0.15);
        }
        .card h3 {
            margin-bottom: 0.5rem;
            font-size: 2rem; /* Increased font size */
            color: var(--primary);
            text-align: center; /* Center align the text */
        }
        .card p {
            font-size: 1.5rem; /* Increased font size */
            color: var(--dark);
            text-align: center; /* Center align the text */
        }

    .center_total {
      text-align: center;
      font-size: large;
      /* Adjust this value as needed */
      font-weight: bold;
      margin: 0.5rem 0;
    }

    /* Chart Container */
    .chart-container {
      background: var(--white);
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
      border: 1px solid var(--border-color);
    }

    .list-container {
      margin-top: 1.5rem;
      background: var(--white);
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
      border: 1px solid var(--border-color);
    }

    .list-title {
      margin-bottom: 1rem;
      color: var(--primary);
      font-size: 1.25rem;
      font-weight: 700;
    }

    .table-wrap {
      overflow-x: auto;
    }

    .tickets-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 920px;
    }

    .tickets-table th,
    .tickets-table td {
      border-bottom: 1px solid #e5e7eb;
      padding: 0.7rem 0.75rem;
      text-align: left;
      font-size: 0.92rem;
    }

    .tickets-table th {
      background: #f8fafc;
      color: #1f2937;
      font-weight: 700;
      position: sticky;
      top: 0;
      z-index: 1;
    }

    .status-pill {
      display: inline-block;
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      background: #dcfce7;
      color: #166534;
      font-weight: 600;
      font-size: 0.8rem;
    }

    .status-pill.pending {
      background: #fef3c7;
      color: #92400e;
    }

    .list-controls {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-bottom: 0.75rem;
      align-items: end;
    }

    .control-group {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      min-width: 180px;
      flex: 1;
    }

    .control-group label {
      font-size: 0.85rem;
      color: #374151;
      font-weight: 600;
    }

    .control-input {
      height: 40px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      padding: 0.4rem 0.6rem;
      font-size: 0.92rem;
      background: #fff;
    }

    .control-actions {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }

    .btn-filter {
      border: none;
      background: var(--primary);
      color: #fff;
      height: 40px;
      padding: 0 0.95rem;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
    }

    .btn-reset {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 40px;
      padding: 0 0.95rem;
      border-radius: 8px;
      border: 1px solid #d1d5db;
      color: #374151;
      text-decoration: none;
      font-weight: 600;
      background: #fff;
    }

    .list-meta {
      font-size: 0.86rem;
      color: #6b7280;
      margin-bottom: 0.8rem;
    }

    .pagination {
      margin-top: 0.9rem;
      display: flex;
      flex-wrap: wrap;
      gap: 0.35rem;
      align-items: center;
    }

    .page-link {
      text-decoration: none;
      color: #1f2937;
      border: 1px solid #d1d5db;
      border-radius: 7px;
      padding: 0.35rem 0.65rem;
      font-size: 0.86rem;
      background: #fff;
    }

    .page-link.active {
      background: var(--primary);
      color: #fff;
      border-color: var(--primary);
    }

    .page-link.disabled {
      pointer-events: none;
      opacity: 0.45;
    }


    /* Responsive Adjustments */
    @media (max-width: 768px) {
      .dashboard-container {
        flex-direction: column;
      }

      aside.sidebar {
        width: 100%;
        text-align: center;
        padding: 15px;
        position: relative;
        box-shadow: none;
      }

      .main-content {
        margin-left: 0;
        width: 100%;
        padding: 10px;
      }
    }
  </style>
</head>

<body>
  <header>
    <!-- Profile Icon & Dropdown -->
    <div class="profile" id="profileIcon">
      <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile">
      <div class="profile-dropdown" id="profileDropdown">
        <a href="update.php">Update</a>
        <a href="logout.php">Logout</a>
      </div>
    </div>
  </header>

  <div class="dashboard-container">
    <!-- Sidebar Navigation -->
    <?php render_dashboard_sidebar('/CPMraffle/activated_tickets.php', $isAdmin, $dashboardPath); ?>

    <!-- Main Content Area -->
    <main class="main-content">
      <!-- Ticket Card Section -->
      <div class="cards">
        <div class="card">
          <h3 style="color: rgba(6,6,93,255);">Total Activated Tickets</h3>
          <p class="center_total"><?php echo htmlspecialchars($totalActivated); ?></p>
        </div>
      </div>

      <!-- Chart Section -->
      <div class="chart-container">
        <canvas id="ticketChart"></canvas>
      </div>

      <!-- Activated Ticket List -->
      <div class="list-container">
        <h3 class="list-title">Activated Ticket List</h3>
        <form method="GET" class="list-controls">
          <div class="control-group">
            <label for="q">Search</label>
            <input
              id="q"
              name="q"
              type="text"
              class="control-input"
              value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES); ?>"
              placeholder="Ticket #, name, email, contact">
          </div>
          <div class="control-group" style="max-width: 220px;">
            <label for="status">Status</label>
            <select id="status" name="status" class="control-input">
              <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
              <option value="activated" <?php echo $statusFilter === 'activated' ? 'selected' : ''; ?>>Activated</option>
              <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            </select>
          </div>
          <div class="control-actions">
            <button class="btn-filter" type="submit">Apply</button>
            <a class="btn-reset" href="/CPMraffle/activated_tickets.php">Reset</a>
          </div>
        </form>

        <p class="list-meta">Showing <?php echo htmlspecialchars((string) $fromRow, ENT_QUOTES); ?> - <?php echo htmlspecialchars((string) $toRow, ENT_QUOTES); ?> of <?php echo htmlspecialchars((string) $totalRows, ENT_QUOTES); ?> records.</p>

        <?php if ($activatedTicketRows === []): ?>
          <p>No activated tickets found yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="tickets-table">
              <thead>
                <tr>
                  <th>Ticket Number</th>
                  <th>Full Name</th>
                  <th>Email</th>
                  <th>Contact Number</th>
                  <th>Date Activated</th>
                  <th>Time Activated</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($activatedTicketRows as $activatedTicket): ?>
                  <?php
                    $activatedAt = trim((string) ($activatedTicket['activation_datetime'] ?? ''));
                    $dateActivated = $activatedAt !== '' ? date('Y-m-d', strtotime($activatedAt)) : '';
                    $timeActivated = $activatedAt !== '' ? date('h:i A', strtotime($activatedAt)) : '';
                    $statusValue = strtolower(trim((string) ($activatedTicket['status'] ?? 'activated')));
                    $statusClass = $statusValue === 'pending' ? 'status-pill pending' : 'status-pill';
                  ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string) ($activatedTicket['ticket_number'] ?? ''), ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars((string) ($activatedTicket['full_name'] ?? ''), ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars((string) ($activatedTicket['email'] ?? ''), ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars((string) ($activatedTicket['contact_number'] ?? ''), ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($dateActivated, ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($timeActivated, ENT_QUOTES); ?></td>
                    <td><span class="<?php echo htmlspecialchars($statusClass, ENT_QUOTES); ?>"><?php echo htmlspecialchars($statusValue !== '' ? $statusValue : 'activated', ENT_QUOTES); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
            <div class="pagination">
              <?php
                $prevPage = max(1, $page - 1);
                $nextPage = min($totalPages, $page + 1);
                $prevQuery = $baseQuery;
                $prevQuery['page'] = $prevPage;
                $nextQuery = $baseQuery;
                $nextQuery['page'] = $nextPage;
              ?>
              <a class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES); ?>">Previous</a>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $pageQuery = $baseQuery; $pageQuery['page'] = $p; ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($pageQuery), ENT_QUOTES); ?>"><?php echo htmlspecialchars((string) $p, ENT_QUOTES); ?></a>
              <?php endfor; ?>
              <a class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?<?php echo htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES); ?>">Next</a>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    // Toggle profile dropdown visibility
    document.getElementById('profileIcon').addEventListener('click', function(event) {
      event.stopPropagation();
      document.getElementById('profileDropdown').classList.toggle('show');
    });
    window.addEventListener('click', function(event) {
      if (!document.getElementById('profileIcon').contains(event.target)) {
        document.getElementById('profileDropdown').classList.remove('show');
      }
    });

    // Prepare chart data from PHP variables
    var chartLabels = <?php echo json_encode($chartLabels); ?>;
    var chartData = <?php echo json_encode($chartData); ?>;

    // Create a line chart using Chart.js if data is available
    if (chartLabels.length > 0 && chartData.length > 0) {
      var ctx = document.getElementById('ticketChart').getContext('2d');
      var ticketChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: chartLabels,
          datasets: [{
            label: 'Activated Tickets by Month',
            data: chartData,
            fill: false,
            borderColor: 'rgba(41, 128, 185, 1)',
            tension: 0.1
          }]
        },
        options: {
          responsive: true,
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0
              }
            }
          }
        }
      });
    }
  </script>
</body>

</html>

