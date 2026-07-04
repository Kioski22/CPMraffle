<?php
// generate_pdf.php
require "vendor/autoload.php";
require 'db.php'; // Your database connection

// Define the background image file (adjust the path if needed)
$backgroundImage = __DIR__ . '/img/ticket.png';

// Check that the background image exists
if (!file_exists($backgroundImage)) {
    die("Background image not found at: " . $backgroundImage);
}

// Check if ticket_number parameter is provided via GET
if (!isset($_GET['ticket_number'])) {
    die("No ticket number specified.");
}

$ticketNumber = $_GET['ticket_number'];

// Prepare query to fetch the specific ticket that can still be downloaded (max 2 times)
$stmt = $conn->prepare("SELECT * FROM generated_tickets WHERE ticket_number = ? AND (download_count < 2 OR download_count IS NULL) LIMIT 1");
$stmt->bind_param("s", $ticketNumber);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Ticket not found or maximum downloads reached.");
}

$ticketRow = $result->fetch_assoc();

/// --- Create the PDF for a single ticket ---
// Use a custom size for a standard raffle ticket (140 mm x 50 mm)
$pdf = new FPDF('L', 'mm', array(101.6, 50.8)); // Portrait mode with custom dimensions
$pdf->SetMargins(0, 0, 0);

// Define ticket dimensions (using the new standard size)
$ticketWidth = 101.6;   // 140 mm
$ticketHeight = 50.8;   // 50 mm

// Parameters for QR code and text
$qrWidth = 15;        // QR code width in mm
$qrXOffset = 6.5;       // Right offset inside ticket container
$qrYOffset = 13;       // Top offset inside ticket container
$ticketPadding = 3;   // Inner padding for text

// For this single ticket, we position it at the top-left corner (x=0, y=0)
$x = 0;
$y = 0;

// Add a page to the PDF
$pdf->AddPage();

// Draw the ticket background
$pdf->Image($backgroundImage, $x, $y, $ticketWidth, $ticketHeight);

// Draw a border around the ticket container (as a cutting guide)
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($x, $y, $ticketWidth, $ticketHeight);

// Get the ticket number from the fetched row (it should match the provided value)
$ticketNumber = $ticketRow['ticket_number'];

// Build the path to the QR code image (stored in the 'QR' folder)
$qrPath = __DIR__ . '/QR/' . $ticketNumber . '.png';

// Overlay the QR code if it exists
if (file_exists($qrPath)) {
    // Position the QR code
    $qrX = $x + $ticketWidth - $qrWidth - $qrXOffset;  // Right-aligned, with offset
    $qrY = $y + $qrYOffset;  // Top-aligned, with offset
    $pdf->Image($qrPath, $qrX, $qrY, $qrWidth);
} else {
    // Optionally display an error message if the QR image is not found
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(255, 0, 0);
    $pdf->SetXY($x + $ticketPadding, $y + $ticketPadding);
    $pdf->Cell(0, 5, "QR not found", 0, 1);
}

// Set offsets for ticket number text (adjust these values as needed)
$codeXOffset = 76;  // Position from the left edge
$codeYOffset = $ticketHeight - 11;  // Position from the bottom edge

// Set font and color for the ticket number
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(100, 100, 100);
$codeX = $x + $codeXOffset;
$codeY = $y + $codeYOffset;
// Print the ticket number on the ticket
$pdf->Text($codeX, $codeY, $ticketNumber);

// --- Update the download count ---
// If download_count is NULL, treat it as 0 and set it to 1; otherwise, increment by 1.
$updateStmt = $conn->prepare("UPDATE generated_tickets SET download_count = IFNULL(download_count, 0) + 1 WHERE ticket_number = ?");
$updateStmt->bind_param("s", $ticketNumber);
$updateStmt->execute();
$updateStmt->close();

$stmt->close();
$conn->close();

// Build a filename for the generated PDF using the ticket number
$filename = "ticket_{$ticketNumber}.pdf";

// Output the PDF to force a direct download with the specified filename
$pdf->Output('D', $filename);
?>
