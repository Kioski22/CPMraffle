<?php
require 'db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/auth.php';

auth_require_roles(['admin']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

// Fetch user profile picture
$profile_picture = "https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png";
$user_id = $_SESSION['id'];
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

// Function to get the next available ticket number safely
function getNextTicketNumber($conn) {
    $result = $conn->query("SELECT ticket_number FROM tickets ORDER BY ticket_number DESC LIMIT 1");
    $lastTicketNumber = 0;

    if ($result && $result->num_rows > 0) {
        $lastTicket = $result->fetch_assoc();
        if (preg_match('/[-_](\d{5})$/', (string) $lastTicket['ticket_number'], $matches)) {
            $lastTicketNumber = (int)$matches[1]; // Extract last number
        }
    }

    return $lastTicketNumber + 1; // Increment safely
}

// Function to generate a sequential ticket number with 5-digit format
function generateUniqueTicket($ticketNumber) {
    return ticket_current_number_prefix() . "_" . str_pad($ticketNumber, 5, "0", STR_PAD_LEFT);
}

// Function to create ticket records (QR image generation disabled).
function generateTicket($ticketNumber, $conn) {
    global $appConfig;

    $encodedTicketNumber = base64_encode($ticketNumber);
    $baseUrl = rtrim((string) ($appConfig['app']['base_url'] ?? 'https://icto2.psmeinc.org.ph/CPMraffle'), '/');
    $instructionLink = $baseUrl . "/instruction.php?ticket=" . urlencode($encodedTicketNumber);
    $verificationLink = $instructionLink;

    // Insert into 'tickets' table
    $stmt = $conn->prepare("INSERT INTO tickets (ticket_number, status, instruction_link, verification_link, created_at) VALUES (?, 'pending', ?, ?, NOW())");
    $stmt->bind_param("sss", $ticketNumber, $instructionLink, $verificationLink);
    if (!$stmt->execute()) {
        die("Error inserting ticket: " . $stmt->error);
    }
    
    // Get the last inserted ticket ID
    $qr_code_id = $stmt->insert_id;
    $stmt->close();

    // Insert into 'generated_tickets' table with qr_code_id as foreign key
    $generatedTicket = $conn->prepare("INSERT INTO generated_tickets (qr_code_id, ticket_number, instruction_link, verification_link, created_at, download) VALUES (?, ?, ?, ?, NOW(), 'no')");
    $generatedTicket->bind_param("isss", $qr_code_id, $ticketNumber, $instructionLink, $verificationLink);
    if (!$generatedTicket->execute()) {
        die("Error inserting generated ticket: " . $generatedTicket->error);
    }
    $generatedTicket->close();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['num_tickets']) && $_POST['num_tickets'] > 0) {
    $numberOfTickets = (int)$_POST['num_tickets'];
    $ticketStartNumber = getNextTicketNumber($conn);

    try {
        for ($i = 0; $i < $numberOfTickets; $i++) {
            $ticketNumber = generateUniqueTicket($ticketStartNumber + $i);
            generateTicket($ticketNumber, $conn);
        }
        // Close the connection before redirecting
        $conn->close();

        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (Exception $e) {
        $message = "Error generating tickets: " . $e->getMessage();
        $conn->close();
    }
}

// Check for a success flag in GET parameters to display a success message.
$message = "";
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Tickets generated successfully!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Tickets</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
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
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light-bg: #f4f6f9;
            --border-color: #e8eef5;
            --dark-text: #2c3e50;
            --gray-text: #6c757d;
            --transition: 0.3s ease;
        }

        body {
            background: linear-gradient(135deg, var(--light-bg) 0%, #ffffff 100%);
            min-height: 100vh;
            color: var(--dark-text);
        }

        /* Header Gradient */
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 2.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(40, 53, 85, 0.15);
            position: relative;
            overflow: hidden;
            margin-left: -2rem;
            margin-right: -2rem;
            padding-left: 2rem;
            padding-right: 2rem;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 1;
        }

        .page-header .subtitle {
            font-size: 1rem;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        /* Main Container */
        .container {
            max-width: 600px;
            margin: 2rem auto;
            width: 100%;
        }

        /* Card Styling */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
            transition: box-shadow var(--transition), transform var(--transition);
        }

        .form-card:hover {
            box-shadow: 0 12px 40px rgba(40, 53, 85, 0.15);
            transform: translateY(-2px);
        }

        /* Form Group Styling */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            font-size: 1rem;
        }

        .form-label i {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        .form-control {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.1);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--gray-text);
        }

        /* Button Styling */
        .btn-generate {
            width: 100%;
            padding: 0.9rem 1.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            background: linear-gradient(135deg, var(--accent) 0%, #ff5722 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
            background: linear-gradient(135deg, #ff5722 0%, #ff6b35 100%);
        }

        .btn-generate:active {
            transform: translateY(0);
        }

        .btn-generate:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Spinner */
        .loading-container {
            display: none;
            text-align: center;
            margin-top: 2rem;
            animation: fadeIn 0.3s ease;
        }

        .spinner-border {
            color: var(--accent) !important;
            width: 3rem;
            height: 3rem;
            margin: 0 auto 1rem;
        }

        .loading-text {
            font-size: 1.1rem;
            color: var(--dark-text);
            font-weight: 500;
        }

        /* Alert Messages */
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInDown 0.4s ease;
        }

        .alert-success i {
            font-size: 1.3rem;
            color: var(--success);
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInDown 0.4s ease;
        }

        .alert-error i {
            font-size: 1.3rem;
            color: var(--danger);
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Help Text */
        .form-hint {
            font-size: 0.85rem;
            color: var(--gray-text);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .form-hint i {
            color: var(--accent);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 1.8rem;
            }

            .form-card {
                padding: 1.5rem;
                margin: 0 1rem;
            }

            .container {
                width: 90%;
            }

            .page-header {
                margin-left: -10px;
                margin-right: -10px;
                padding-left: 10px;
                padding-right: 10px;
            }
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
            z-index: 10;
        }

        .profile {
            position: relative;
            cursor: pointer;
            display: inline-block;
        }

        .profile img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            transition: transform var(--transition) ease, box-shadow 0.3s ease;
            object-fit: cover;
            border: 2px solid white;
        }

        .profile img:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

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

        .profile-dropdown a {
            display: block;
            padding: 12px 20px;
            color: var(--dark-text);
            text-decoration: none;
            font-size: 14px;
            transition: background var(--transition) ease, padding-left 0.3s;
            border-bottom: 1px solid #eee;
        }

        .profile-dropdown a:hover {
            background: #f0f0f0;
            padding-left: 25px;
        }

        .profile-dropdown.show {
            display: block;
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        /* Sidebar */
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
            color: white;
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
            color: #f4f6f9;
            text-decoration: none;
            font-size: 16px;
            transition: background var(--transition) ease, padding-left var(--transition) ease;
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

        /* Main Content Area */
        main.main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        @media (max-width: 768px) {
            aside.sidebar {
                width: 100%;
                position: relative;
                box-shadow: none;
            }

            main.main-content {
                margin-left: 0;
                padding: 10px;
            }

            .dashboard-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<!-- Header -->
<header>
    <div class="profile" id="profileIcon">
        <img src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES); ?>" alt="Profile" />
        <div class="profile-dropdown" id="profileDropdown">
            <a href="update.php">Update</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="<?php echo htmlspecialchars($dashboardPath, ENT_QUOTES); ?>" style="text-decoration:none;">
                <h1>6th PSME CPM National Conference Dashboard</h1>
            </a>
        </div>
        <hr>
        <nav>
            <ul>
                <li><a href="/CPMraffle/activated_tickets.php"><i class="fas fa-check-circle"></i> Activated Tickets</a></li>
                <li><a href="/CPMraffle/pending_tickets.php"><i class="fas fa-hourglass-half"></i> Pending Tickets</a></li>
                <?php if ($isAdmin): ?>
                <li><a class="active" href="/CPMraffle/generate_qr.php"><i class="fas fa-ticket-alt"></i> Ticket Generation</a></li>
                <?php endif; ?>
                <li><a href="/CPMraffle/pool_tickets.php"><i class="fas fa-layer-group"></i> Ticket Pool</a></li>
                <li><a href="/CPMraffle/buyer.php"><i class="fas fa-user-plus"></i> Buyer Assignment</a></li>
                <li><a href="/CPMraffle/bulk_buyer_upload_admin.php"><i class="fas fa-file-upload"></i> Bulk Buyer Upload</a></li>
                <li><a href="/CPMraffle/participant_list.php"><i class="fas fa-users"></i> Assigned Tickets</a></li>
                <?php if ($isAdmin): ?>
                <li><a href="/CPMraffle/ticket_layout_editor.php"><i class="fas fa-object-group"></i> Ticket Layout</a></li>
                <li><a href="/CPMraffle/email_template_settings.php"><i class="fas fa-envelope-open-text"></i> Email Templates</a></li>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                <li><a href="/CPMraffle/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="container-fluid">
                <div class="text-center">
                    <h1><i class="fas fa-ticket-alt"></i> Generate Tickets</h1>
                    <p class="subtitle">Create raffle tickets in bulk</p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container">
            <div class="form-card">
                <!-- Form -->
                <form method="POST" id="ticketForm">
                    <div class="form-group">
                        <label for="num_tickets" class="form-label">
                            <i class="fas fa-list-ol"></i> Number of Tickets
                        </label>
                        <input 
                            type="number" 
                            id="num_tickets" 
                            name="num_tickets" 
                            class="form-control" 
                            min="1" 
                            max="10000" 
                            placeholder="Enter number of tickets to generate"
                            required
                        >
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            <span>Enter a number between 1 and 10,000</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-generate">
                        <i class="fas fa-magic"></i> Generate Tickets
                    </button>
                </form>

                <!-- Loading Message -->
                <div class="loading-container" id="loadingMessage">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="loading-text">Generating tickets, please wait...</p>
                </div>

                <!-- Success Message -->
                <?php if (!empty($message) && strpos($message, 'successfully') !== false): ?>
                    <div class="alert-success">
                        <i class="fas fa-check-circle"></i>
                        <strong><?php echo htmlspecialchars($message); ?></strong>
                    </div>
                <?php elseif (!empty($message)): ?>
                    <div class="alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong><?php echo htmlspecialchars($message); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Toggle profile dropdown
    document.getElementById('profileIcon').addEventListener('click', function(event) {
        event.stopPropagation();
        document.getElementById('profileDropdown').classList.toggle('show');
    });
    window.addEventListener('click', function(event) {
        if (!event.target.closest('.profile')) {
            document.getElementById('profileDropdown').classList.remove('show');
        }
    });

    document.getElementById("ticketForm").addEventListener("submit", function(event) {
        const numTickets = document.getElementById("num_tickets").value;
        
        // Validation
        if (!numTickets || numTickets < 1 || numTickets > 10000) {
            event.preventDefault();
            alert("Please enter a valid number between 1 and 10,000");
            return;
        }

        // Show loading message and disable button
        document.getElementById("loadingMessage").style.display = "block";
        document.querySelector(".btn-generate").disabled = true;
        document.querySelector(".btn-generate").innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    });
</script>
    </main>
</div>

</body>
</html>


