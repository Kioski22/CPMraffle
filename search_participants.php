<?php
include 'db.php';
require_once __DIR__ . '/auth.php';

if (!auth_is_logged_in() || !auth_is_admin()) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$search = isset($_POST['search']) ? $_POST['search'] : '';
$search = "%{$search}%";

$query = "SELECT a.ticket_number, a.full_name, a.activation_datetime, a.status, t.email, t.contact_number 
          FROM ticket_activations a 
          JOIN tickets t ON a.ticket_number = t.ticket_number 
          WHERE a.full_name LIKE ? 
             OR a.ticket_number LIKE ? 
             OR t.email LIKE ? 
             OR t.contact_number LIKE ? 
             OR a.activation_datetime LIKE ?
          ORDER BY a.activation_datetime DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("sssss", $search, $search, $search, $search, $search);
$stmt->execute();
$result = $stmt->get_result();

$participants = [];
while ($row = $result->fetch_assoc()) {
    $row['date_activated'] = date('Y-m-d', strtotime($row['activation_datetime']));
    $row['time_activated'] = date('h:i A', strtotime($row['activation_datetime']));
    $participants[] = $row;
}

header('Content-Type: application/json');
echo json_encode($participants);