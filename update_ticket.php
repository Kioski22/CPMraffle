<?php
// update_ticket.php

include 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $ticket_number = $_POST['ticket_number'] ?? null;
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact = $_POST['contact_number'] ?? '';

    // Basic validation
    if (!$ticket_number || empty($full_name) || empty($email) || empty($contact)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    // Update the record in the tickets table
    // Note: Using "sss" to bind all as strings. Adjust if your schema differs.
    $query = "UPDATE tickets SET full_name = ?, email = ?, contact_number = ? WHERE ticket_number = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("ssss", $full_name, $email, $contact, $ticket_number);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Record updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating record: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
