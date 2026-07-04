<?php
require 'db.php'; // Adjust the path to your db.php file

// Ensure that ticket_number is provided
if (isset($_GET['ticket_number'])) {
    $ticket_number = $_GET['ticket_number'];
    
    // Prepare SQL query to get ticket details based on ticket_number
    $sql = "SELECT * FROM tickets WHERE ticket_number = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        // Bind the ticket number parameter
        $stmt->bind_param("s", $ticket_number);

        // Execute the query
        $stmt->execute();
        
        // Get the result of the query
        $result = $stmt->get_result();

        // Check if any rows are returned (ticket found)
        if ($result->num_rows > 0) {
            // Fetch the ticket details
            $ticket = $result->fetch_assoc();
            
            // Check the status of the ticket
            if (strcasecmp($ticket['status'], 'activated') === 0 || strcasecmp($ticket['status'], 'pending') === 0) {
                // Assuming you have a 'fullname' column in your 'tickets' table
                // Or if the 'fullname' is in a related table, you can adjust the query accordingly.
                $full_name = $ticket['full_name']; // Or fetch from a related table if needed

                // Return the ticket status and the full name
                echo json_encode([
                    "status" => strtolower($ticket['status']),
                    "full_name" => $full_name
                ]);
            } else {
                // Invalid status
                echo json_encode(["status" => "Invalid ticket status."]);
            }
        } else {
            // Ticket not found
            echo json_encode(["status" => "Ticket not found."]);
        }

        // Close the prepared statement
        $stmt->close();
    } else {
        // Query preparation failed
        echo json_encode(["status" => "Failed to prepare the SQL query."]);
    }
} else {
    // Ticket number not provided
    echo json_encode(["status" => "Ticket number not provided."]);
}

// Close the database connection
$conn->close();
?>
