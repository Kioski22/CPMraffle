<?php
// Include database connection
require 'db.php'; // adjust path as needed

$activationClosed = ticket_activation_is_closed();

// Check if the 'ticket' parameter is passed in the URL
if (isset($_GET['ticket'])) {
    $hashedTicket = $_GET['ticket'];
    $decodedTicket = base64_decode($hashedTicket);

    if ($decodedTicket === false || empty($decodedTicket)) {
        echo "<p><strong>Invalid ticket number</strong>. The ticket number is corrupted or not valid.</p>";
        exit;
    }

    // Prepare and execute a query to retrieve ticket information from the database
    $stmt = $conn->prepare("SELECT * FROM tickets WHERE ticket_number = ?");
    $stmt->bind_param("s", $decodedTicket);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Fetch ticket data
        $ticket = $result->fetch_assoc();
    } else {
        echo "<p><strong>Ticket not found.</strong> Please make sure the ticket number is correct.</p>";
        $ticket = null; // Ensure that we have a fallback if the ticket is not found
    }

    $stmt->close();
} else {
    echo "<p><strong>Invalid ticket number</strong>. The 'ticket' parameter is missing or invalid.</p>";
    $ticket = null; // Ensure that we have a fallback if no ticket parameter is provided
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Instructions</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
   <link rel="icon" type="image/x-icon" href="img/favicon.ico">
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
            --light: #f4f6f9;
            --dark: #2c3e50;
            --white: #ffffff;
            --gray: #6c757d;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --border-color: #e8eef5;
            --transition: 0.3s ease;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: var(--dark);
        }

        .steps-mechanics {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(40, 53, 85, 0.12);
            color: var(--dark);
            border: 1px solid var(--border-color);
        }

        .container {
            background: var(--white);
            padding: 2em;
            border-radius: 16px;
            box-shadow: 0 12px 50px rgba(40, 53, 85, 0.15);
            max-width: 900px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease;
            border: 1px solid var(--border-color);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .mechanics {
            border: 1px solid var(--border-color);
            padding: 20px;
            margin: 1.5em 0;
            border-radius: 12px;
            background: rgba(40, 53, 85, 0.02);
            transition: all var(--transition);
        }

        .mechanics:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(40, 53, 85, 0.1);
        }

        h1 {
            font-size: 2.4em;
            color: var(--primary);
            margin-bottom: 0.7em;
            text-align: center;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        h1 i {
            color: var(--accent);
        }

        .status-message {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.1) 0%, rgba(39, 174, 96, 0.05) 100%);
            color: var(--success);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 2em;
            font-size: 1.1em;
            text-align: center;
            border: 1px solid rgba(39, 174, 96, 0.3);
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5em 0;
        }

        table th,
        table td {
            padding: 15px;
            font-size: 1.1em;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
        }

        table th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            font-weight: 600;
        }

        table td {
            background: var(--white);
        }

        table tbody tr:hover {
            background: var(--light);
        }

        .button-container {
            display: flex;
            justify-content: center;
            margin-top: 2em;
            gap: 1rem;
            flex-wrap: wrap;
        }

        button {
            padding: 12px 32px;
            margin-bottom: 10px;
            font-size: 1.1em;
            background: linear-gradient(135deg, var(--accent) 0%, #ff5722 100%);
            font-weight: bold;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
            min-width: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        button:hover {
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
            transform: translateY(-2px);
        }

        button:active {
            transform: translateY(0);
        }

        .mechanics ul {
            list-style: none;
            padding: 0;
            font-size: 1.1em;
        }

        .mechanics li {
            margin-bottom: 15px;
            padding-left: 2rem;
            position: relative;
        }

        .mechanics li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--accent);
            font-weight: bold;
            font-size: 1.2em;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .container {
                width: 90%;
                padding: 1.2em;
            }

            h1 {
                font-size: 1.8em;
            }

            table th,
            table td {
                padding: 10px;
                font-size: 1em;
            }

            .button-container {
                flex-direction: column;
                align-items: center;
            }

            button {
                width: 90%;
                padding: 10px 20px;
                min-width: auto;
            }
        }

        /* Small Mobile View (Max Width 480px) */
        @media (max-width: 480px) {
            h1 {
                font-size: 1.6em;
                gap: 0.5rem;
            }

            .container {
                width: 85%;
                padding: 0.8em;
            }

            table th,
            table td {
                font-size: 0.9em;
                padding: 8px;
            }

            .mechanics {
                padding: 15px;
            }

            button {
                font-size: 1em;
                padding: 8px 15px;
            }
        }

    </style>
</head>

<body>
    <div class="container">
        <!-- Ticket Instructions -->
        <?php if ($ticket): ?>
            <h1><i class="fas fa-ticket-alt"></i> Ticket Instructions for <?php echo htmlspecialchars($ticket['ticket_number']); ?></h1>

            <?php if (strcasecmp($ticket['status'], 'activated') === 0): ?>
                <!-- Show the activation status -->
                <div class="status-message">
                    <strong>Ticket <?php echo htmlspecialchars($ticket['ticket_number']); ?> is Activated!</strong><br>
                    You're now eligible to win amazing prizes.
                </div>
            <?php elseif ($activationClosed): ?>
                <div class="status-message" style="background-color: #f8d7da; color: #842029;">
                    <strong>Ticket activation is now closed.</strong><br>
                    Activations were accepted until <?php echo htmlspecialchars(ticket_activation_cutoff_label()); ?>.
                </div>
            <?php else: ?>
                <!-- Display the ticket instructions if it's not activated -->
                <div class="steps-mechanics">
                <table class="table table-bordered">
                    <tr>
                        <th>Step 1</th>
                        <td>Scan the QR code you received on your ticket to open the instructions page.</td>
                    </tr>
                    <tr>
                        <th>Step 2</th>
                        <td>Check the status of your ticket. If the status is 'pending', follow the instructions below to complete the process.</td>
                    </tr>
                    <tr>
                        <th>Step 3</th>
                        <td>Follow the verification link provided to verify your ticket details and confirm your participation.</td>
                    </tr>
                    <tr>
                        <th>Step 4</th>
                        <td>If you encounter any issues, feel free to contact our support team for assistance.</td>
                    </tr>
                </table>

                 <div class="mechanics">
                    <h2>Raffle Mechanics</h2>
                                        <ul>
                                                <li><strong>Event:</strong> 6th PSME CPM National Conference</li>
                                                <li><strong>Organizer:</strong> Philippine Society of Mechanical Engineers Inc. (PSME), Organized by PSME Las Pinas-Muntinlupa (LPM) Chapter, in partnership with PSME WEN (Women Engineers' Network)</li>
                                                <li><strong>Theme:</strong> "PSME FORWARD: Empowering CPMs Through Modern Learning and Inclusive Growth"</li>
                                                <li><strong>Date:</strong> September 4-5, 2026</li>
                                                <li><strong>Venue:</strong> Polaris Corporate Center Spectrum Midway Filinvest Corporate City, Alabang, Muntinlupa</li>
                                                <li><strong>PSME Information:</strong> PRC Accredited Integrated Professional Organization, Certificate No. AIPO-004 - "The Home of Resilient Filipino Mechanical Engineers &amp; CPMs"</li>
                                                <li>The raffle is open to all individuals who purchase tickets for the 6th PSME CPM National Conference.</li>
                        <li>There is no need to remit or drop off the physical raffle tickets during the draw. However, the buyer must register and activate the ticket to be eligible by scanning the QR code and activating it.</li>
                        <li>Printed raffle tickets with QR codes distributed to Chapter Presidents are considered sold once presented.</li>
                        <li>The deadline to register your raffle ticket is September 5, 2026, at 3:00 PM.</li>
                        <li>Any raffle ticket that is not registered or activated by the deadline is considered null and void.</li>
                        <li>The raffle draw will be conducted manually using a random number draw with pingpong balls and transparent containers.</li>
                                                <li>The winning raffle numbers will be drawn on September 5, 2026, at 4:00 PM at Polaris Corporate Center Spectrum Midway Filinvest Corporate City, Alabang, Muntinlupa.</li>
                        <li>The results will be posted on the PSME Inc. Facebook Page and the PSME website.</li>
                        <li>Winners will also be notified via phone and email.</li>
                        <li>Winners must claim their prizes within sixty (60) days from the date of the raffle draw. Unclaimed prizes after this period will be forfeited in favor of the PSME National Office.</li>
                        <li>To claim the prize, winners or their authorized representatives must present the winning raffle ticket and two (2) valid government-issued IDs.</li>
                        <li>Prizes must be claimed from the designated dealers. However, certain prizes must be claimed directly at the PSME National Office, located at 19 Scout Bayoran St., Brgy. South Triangle, Quezon City.<br><br><strong>Note:</strong> LTO Registration, Insurance, Transportation, and other related expenses shall be shouldered by the winner.</li>
                                        </ul>
                </div>
                </div>

                <div class="button-container">
                    <a href="<?php echo isset($ticket['verification_link']) ? htmlspecialchars($ticket['verification_link']) : '#'; ?>">
                        <button>Verify Your Ticket</button>
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p><strong>Ticket not found.</strong> Please make sure the ticket number is correct.</p>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS & Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz4fnFO9gybGz2s6H6oBz5y0kWfF7kKtbzTtY39fw5lyc3U4j3vWrDnk0U" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js" integrity="sha384-pzjw8f+ua7Kw1TIq0t5hY0GTx5wnzmz4+sO5e46z5UIMgDpzqxB7e14wqyskuh3f" crossorigin="anonymous"></script>

</body>

</html>