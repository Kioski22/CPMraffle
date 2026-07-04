<?php
include 'db.php';
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);

// Set headers for CSV file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=participants.csv');

$output = fopen('php://output', 'w');

// CSV column headers
fputcsv($output, ['Ticket Number', 'Full Name', 'Email', 'Contact Number', 'Date Activated', 'Time Activated', 'Status']);

// Query to fetch ALL data (no pagination)
$sql = "SELECT 
            a.ticket_number, 
            a.full_name, 
            a.activation_datetime, 
            a.status, 
            t.email, 
            t.contact_number 
        FROM ticket_activations a
        JOIN tickets t ON a.ticket_number = t.ticket_number
        ORDER BY a.activation_datetime DESC";

$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $date = date('Y-m-d', strtotime($row['activation_datetime']));
    $time = date('h:i A', strtotime($row['activation_datetime']));
    
    fputcsv($output, [
        $row['ticket_number'],
        $row['full_name'],
        $row['email'],
        $row['contact_number'],
        $date,
        $time,
        $row['status']
    ]);
}

fclose($output);
exit();
?>