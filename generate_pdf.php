<?php
// generate_pdf.php
require "vendor/autoload.php";
require 'db.php'; // Your database connection

// Define background image file (the ticket image)
// This image should be designed at actual size 2x4 inches
$backgroundImage = __DIR__ . '/img/ticket.png';
if (!file_exists($backgroundImage)) {
    die("Background image not found at: " . $backgroundImage);
}

if (!isset($_GET['ticket_count'])) {
    die("No ticket count specified.");
}
$ticketCount = intval($_GET['ticket_count']);
if ($ticketCount < 1) {
    die("Ticket count must be at least 1.");
}

// Fetch tickets that have not been downloaded yet
$stmt = $conn->prepare("SELECT * FROM generated_tickets WHERE download = 'no' OR download IS NULL ORDER BY ticket_number ASC LIMIT ?");
$stmt->bind_param("i", $ticketCount);
$stmt->execute();
$stmt->store_result();

$meta = $stmt->result_metadata();
$fields = [];
$row = [];
$bindVars = [];
while ($field = $meta->fetch_field()) {
    $fields[$field->name] = null;
    $row[$field->name] = null;
    $bindVars[] = &$row[$field->name];
}
call_user_func_array([$stmt, 'bind_result'], $bindVars);

$tickets = [];
while ($stmt->fetch()) {
    $ticketRow = [];
    foreach ($row as $key => $val) {
        $ticketRow[$key] = $val;
    }
    $tickets[] = $ticketRow;
}
if (count($tickets) === 0) {
    die("No tickets found in database.");
}

$updateStmt = $conn->prepare("UPDATE generated_tickets SET download = 'yes' WHERE ticket_number = ?");

// --- PDF Setup ---
// We are using the long bond paper size: 8.5" x 13"
// In millimeters: 215.9 mm x 330.2 mm
$pageWidth = 215.9;
$pageHeight = 330.2;

// Instantiate FPDF with the long bond paper size (portrait)
$pdf = new FPDF('P', 'mm', array($pageWidth, $pageHeight));
$pdf->SetMargins(0, 0, 0);

// Ticket dimensions
// For a 2"x4" ticket printed in landscape, the ticket is 4" wide and 2" high.
// Convert inches to mm (1 inch = 25.4 mm):
// Ticket width: 4" × 25.4 = 101.6 mm
// Ticket height: 2" × 25.4 = 50.8 mm
$ticketWidth = 101.6;
$ticketHeight = 50.8;

// Grid configuration: 2 columns and 6 rows = 12 tickets per page
$columns = 2;
$rows = 6;
$ticketsPerPage = $columns * $rows;

// Optionally center the grid on the long bond paper.
// Total grid width: 2 * 101.6 mm = 203.2 mm
// Leftover horizontal margin: 215.9 mm - 203.2 mm = 12.7 mm → half margin ~6.35 mm on left.
$gridLeftMargin = 6.35;

// Total grid height: 6 * 50.8 mm = 304.8 mm
// Leftover vertical margin: 330.2 mm - 304.8 mm = 25.4 mm → half margin ~12.7 mm on top.
$gridTopMargin = 12.7;

// QR Code settings (adjust these values as necessary)
$qrWidth = 18;   // QR code width in mm; ensure this fits well in a 2"×4" (actually 4"×2") ticket.
$qrXOffset = 5;  // Right-side offset inside ticket container
$qrYOffset = 14;  // Top-side offset inside ticket container

$ticketPadding = 3; // Optional inner padding for text

$firstTicketNumber = null;
$lastTicketNumber = null;

$pdf->AddPage();

$counter = 0;
foreach ($tickets as $ticketRow) {
    if ($firstTicketNumber === null) {
        $firstTicketNumber = $ticketRow['ticket_number'];
    }
    $lastTicketNumber = $ticketRow['ticket_number'];

    // If current page is full, add a new page
    if ($counter > 0 && $counter % $ticketsPerPage == 0) {
        $pdf->AddPage();
    }

    // Determine position in the grid
    $position = $counter % $ticketsPerPage;
    $col = $position % $columns;
    $row = floor($position / $columns);

    // Compute the top-left coordinate for this ticket cell (offset by grid margins)
    $x = $gridLeftMargin + $col * $ticketWidth;
    $y = $gridTopMargin + $row * $ticketHeight;

    // Place the ticket background image at its natural size (do not stretch)
    $pdf->Image($backgroundImage, $x, $y, $ticketWidth, $ticketHeight);

    // Optional: Draw a border around the ticket for a cutting guide
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $ticketWidth, $ticketHeight);

    $ticketNumber = $ticketRow['ticket_number'];
    $qrPath = __DIR__ . '/QR/' . $ticketNumber . '.png';

    // If a QR code exists, overlay it
    if (file_exists($qrPath)) {
        $qrX = $x + $ticketWidth - $qrWidth - $qrXOffset;
        $qrY = $y + $qrYOffset;
        $pdf->Image($qrPath, $qrX, $qrY, $qrWidth);
    } else {
        // Display error message if QR image isn't found
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetXY($x + $ticketPadding, $y + $ticketPadding);
        $pdf->Cell(0, 5, "QR not found", 0, 1);
    }

    // Print the ticket number (adjust position as needed)
    $codeX = $x + 75;
    $codeY = $y + $ticketHeight - 12;
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->Text($codeX, $codeY, $ticketNumber);

    // Mark the ticket as downloaded
    $updateStmt->bind_param("s", $ticketNumber);
    $updateStmt->execute();

    $counter++;
}

$updateStmt->close();
$stmt->close();
$conn->close();

// Build filename based on ticket numbers
$filename = "tickets {$firstTicketNumber}-{$lastTicketNumber}.pdf";
$pdf->Output('D', $filename);
?>