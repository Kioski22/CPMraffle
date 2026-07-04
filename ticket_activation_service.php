<?php

require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/ticket_mailer.php';

if (!function_exists('ticket_validate_activation_identity')) {
    function ticket_validate_activation_identity(string $fullName, string $email, string $contactNumber): void
    {
        if ($fullName === '' || strlen($fullName) > 120) {
            throw new RuntimeException('Please provide a valid full name.');
        }
        if ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid email address.');
        }
        if ($contactNumber === '' || !preg_match('/^[0-9+\-() ]{7,30}$/', $contactNumber)) {
            throw new RuntimeException('Please provide a valid contact number.');
        }
    }
}

if (!function_exists('ticket_activate_tickets_for_buyer')) {
    function ticket_activate_tickets_for_buyer(mysqli $conn, array $appConfig, int $buyerId, array $ticketNumbers, string $fullName, string $email, string $contactNumber): array
    {
        ensureBuyerSchema($conn);
        ticket_validate_activation_identity($fullName, $email, $contactNumber);

        if (ticket_activation_is_closed()) {
            throw new RuntimeException(ticket_activation_closed_message());
        }

        if ($buyerId <= 0) {
            throw new RuntimeException('Invalid buyer for activation.');
        }
        if (empty($ticketNumbers)) {
            throw new RuntimeException('Select at least one ticket to activate.');
        }

        $normalizedTickets = [];
        foreach ($ticketNumbers as $ticketNumber) {
            $ticketValue = trim((string) $ticketNumber);
            if ($ticketValue !== '') {
                $normalizedTickets[] = $ticketValue;
            }
        }
        $normalizedTickets = array_values(array_unique($normalizedTickets));
        if ($normalizedTickets === []) {
            throw new RuntimeException('Select at least one valid ticket to activate.');
        }

        $placeholders = implode(',', array_fill(0, count($normalizedTickets), '?'));
        $sql = "SELECT ta.ticket_number, LOWER(COALESCE(t.status, 'pending')) AS ticket_status
            FROM ticket_assignments ta
            LEFT JOIN tickets t ON t.ticket_number = ta.ticket_number
            WHERE ta.buyer_id = ? AND ta.ticket_number IN ($placeholders)
            FOR UPDATE";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to validate assigned tickets.');
        }

        $types = 'i' . str_repeat('s', count($normalizedTickets));
        $bindValues = [$types, &$buyerId];
        foreach ($normalizedTickets as $index => $ticketNumber) {
            $bindValues[] = &$normalizedTickets[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        if (count($rows) !== count($normalizedTickets)) {
            throw new RuntimeException('One or more selected tickets are not assigned to this buyer.');
        }

        $activatedTickets = [];
        $conn->begin_transaction();
        try {
            $updateStmt = $conn->prepare("UPDATE tickets
                SET status = 'activated', full_name = ?, email = ?, contact_number = ?
                WHERE ticket_number = ?");
            $checkActivationStmt = $conn->prepare('SELECT 1 FROM ticket_activations WHERE ticket_number = ? LIMIT 1');
            $insertActivationStmt = $conn->prepare("INSERT INTO ticket_activations (ticket_number, full_name, email, status) VALUES (?, ?, ?, 'activated')");
            if (!$updateStmt || !$checkActivationStmt || !$insertActivationStmt) {
                throw new RuntimeException('Unable to prepare activation statements.');
            }

            foreach ($rows as $row) {
                if (($row['ticket_status'] ?? 'pending') === 'activated') {
                    continue;
                }

                $ticketNumber = (string) $row['ticket_number'];
                $updateStmt->bind_param('ssss', $fullName, $email, $contactNumber, $ticketNumber);
                $updateStmt->execute();

                $checkActivationStmt->bind_param('s', $ticketNumber);
                $checkActivationStmt->execute();
                $existingResult = $checkActivationStmt->get_result();
                $alreadyLogged = $existingResult && $existingResult->num_rows > 0;
                if (!$alreadyLogged) {
                    $insertActivationStmt->bind_param('sss', $ticketNumber, $fullName, $email);
                    $insertActivationStmt->execute();
                }

                $activatedTickets[] = $ticketNumber;
            }

            $updateStmt->close();
            $checkActivationStmt->close();
            $insertActivationStmt->close();

            if ($activatedTickets === []) {
                throw new RuntimeException('The selected tickets are already activated.');
            }

            $buyerStatus = buyerSyncStatus($conn, $buyerId);
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $thankYouData = ticket_build_activation_template_data([
            'full_name' => $fullName,
            'email' => $email,
            'contact_number' => $contactNumber,
            'activated_ticket_list' => implode(', ', $activatedTickets),
            'activation_date' => date('Y-m-d H:i:s'),
            'app_name' => (string) (($appConfig['mail']['from_name'] ?? 'Ticketing Team')),
        ]);
        $emailParts = ticket_compose_activation_thankyou_email($appConfig, $thankYouData);
        $deliveryMethod = ticket_send_gmail_message($appConfig, $fullName, $email, $emailParts);

        return [
            'activated_tickets' => $activatedTickets,
            'buyer_status' => $buyerStatus,
            'delivery_method' => $deliveryMethod,
        ];
    }
}

if (!function_exists('ticket_activate_single_ticket')) {
    function ticket_activate_single_ticket(mysqli $conn, array $appConfig, string $ticketNumber, string $fullName, string $email, string $contactNumber): array
    {
        ensureBuyerSchema($conn);
        ticket_validate_activation_identity($fullName, $email, $contactNumber);

        if (ticket_activation_is_closed()) {
            throw new RuntimeException(ticket_activation_closed_message());
        }

        $ticketNumber = trim($ticketNumber);
        if ($ticketNumber === '') {
            throw new RuntimeException('Invalid ticket number.');
        }

        $stmt = $conn->prepare("SELECT t.ticket_number, LOWER(COALESCE(t.status, 'pending')) AS ticket_status, ta.buyer_id
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number = ?
            LIMIT 1
            FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Unable to validate ticket.');
        }

        $stmt->bind_param('s', $ticketNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$ticket) {
            throw new RuntimeException('The ticket could not be verified.');
        }
        if (($ticket['ticket_status'] ?? 'pending') === 'activated') {
            throw new RuntimeException('This ticket is already activated.');
        }

        $conn->begin_transaction();
        try {
            $updateStmt = $conn->prepare("UPDATE tickets
                SET status = 'activated', full_name = ?, email = ?, contact_number = ?
                WHERE ticket_number = ?");
            $checkActivationStmt = $conn->prepare('SELECT 1 FROM ticket_activations WHERE ticket_number = ? LIMIT 1');
            $insertActivationStmt = $conn->prepare("INSERT INTO ticket_activations (ticket_number, full_name, email, status) VALUES (?, ?, ?, 'activated')");
            if (!$updateStmt || !$checkActivationStmt || !$insertActivationStmt) {
                throw new RuntimeException('Unable to prepare activation statements.');
            }

            $updateStmt->bind_param('ssss', $fullName, $email, $contactNumber, $ticketNumber);
            $updateStmt->execute();

            $checkActivationStmt->bind_param('s', $ticketNumber);
            $checkActivationStmt->execute();
            $existingResult = $checkActivationStmt->get_result();
            $alreadyLogged = $existingResult && $existingResult->num_rows > 0;
            if (!$alreadyLogged) {
                $insertActivationStmt->bind_param('sss', $ticketNumber, $fullName, $email);
                $insertActivationStmt->execute();
            }

            $updateStmt->close();
            $checkActivationStmt->close();
            $insertActivationStmt->close();

            $buyerStatus = null;
            $buyerId = isset($ticket['buyer_id']) ? (int) $ticket['buyer_id'] : 0;
            if ($buyerId > 0) {
                $buyerStatus = buyerSyncStatus($conn, $buyerId);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $thankYouData = ticket_build_activation_template_data([
            'full_name' => $fullName,
            'email' => $email,
            'contact_number' => $contactNumber,
            'activated_ticket_list' => $ticketNumber,
            'activation_date' => date('Y-m-d H:i:s'),
            'app_name' => (string) (($appConfig['mail']['from_name'] ?? 'Ticketing Team')),
        ]);
        $emailParts = ticket_compose_activation_thankyou_email($appConfig, $thankYouData);
        $deliveryMethod = ticket_send_gmail_message($appConfig, $fullName, $email, $emailParts);

        return [
            'activated_tickets' => [$ticketNumber],
            'buyer_status' => $buyerStatus,
            'delivery_method' => $deliveryMethod,
        ];
    }
}
