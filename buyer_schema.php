<?php

if (!function_exists('buyer_table_exists')) {
    function buyer_table_exists(mysqli $conn, string $tableName): bool
    {
        $escapedTable = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");
        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->close();
            return $exists;
        }

        return false;
    }
}

if (!function_exists('buyer_add_column_if_missing')) {
    function buyer_add_column_if_missing(mysqli $conn, string $tableName, string $columnDefinition): void
    {
        [$columnName] = explode(' ', $columnDefinition, 2);
        $escapedTable = $conn->real_escape_string($tableName);
        $escapedColumn = $conn->real_escape_string($columnName);
        $columnCheck = $conn->query("SHOW COLUMNS FROM {$escapedTable} LIKE '{$escapedColumn}'");
        if ($columnCheck instanceof mysqli_result) {
            $needsColumn = $columnCheck->num_rows === 0;
            $columnCheck->close();
            if ($needsColumn) {
                $conn->query("ALTER TABLE {$escapedTable} ADD COLUMN {$columnDefinition}");
            }
        }
    }
}

function ensureBuyerSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS buyers (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        buyer_type VARCHAR(20) NOT NULL,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(150) NOT NULL,
        contact VARCHAR(30) NOT NULL,
        chapter VARCHAR(120) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        email_sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $buyerColumns = [
        "activation_selector VARCHAR(32) NULL",
        "activation_token_hash VARCHAR(64) NULL",
        "activation_token_expires_at DATETIME NULL",
        "activation_token_last_used_at DATETIME NULL",
        "activation_portal_sent_at DATETIME NULL",
        "activation_completed_at DATETIME NULL",
    ];
    foreach ($buyerColumns as $columnDefinition) {
        buyer_add_column_if_missing($conn, 'buyers', $columnDefinition);
    }

    $selectorIndex = $conn->query("SHOW INDEX FROM buyers WHERE Key_name = 'uq_buyers_activation_selector'");
    if ($selectorIndex instanceof mysqli_result && $selectorIndex->num_rows === 0) {
        $conn->query("ALTER TABLE buyers ADD UNIQUE KEY uq_buyers_activation_selector (activation_selector)");
    }
    if ($selectorIndex instanceof mysqli_result) {
        $selectorIndex->close();
    }

    if (buyer_table_exists($conn, 'users')) {
        $userColumns = [
            "role VARCHAR(20) NOT NULL DEFAULT 'admin'",
            "email VARCHAR(150) NULL",
            "chapter_name VARCHAR(120) NULL",
        ];
        foreach ($userColumns as $columnDefinition) {
            buyer_add_column_if_missing($conn, 'users', $columnDefinition);
        }
    }

    if (buyer_table_exists($conn, 'tickets')) {
        $ticketColumns = [
            'assigned_chapter_id INT(11) NULL',
            'chapter_assigned_at DATETIME NULL',
        ];
        foreach ($ticketColumns as $columnDefinition) {
            buyer_add_column_if_missing($conn, 'tickets', $columnDefinition);
        }

        $chapterTicketIndex = $conn->query("SHOW INDEX FROM tickets WHERE Key_name = 'idx_tickets_assigned_chapter_id'");
        if ($chapterTicketIndex instanceof mysqli_result && $chapterTicketIndex->num_rows === 0) {
            $conn->query("ALTER TABLE tickets ADD KEY idx_tickets_assigned_chapter_id (assigned_chapter_id)");
        }
        if ($chapterTicketIndex instanceof mysqli_result) {
            $chapterTicketIndex->close();
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS chapter_allocations (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        chapter_id INT(11) NOT NULL,
        admin_id INT(11) NOT NULL,
        start_ticket VARCHAR(80) NOT NULL,
        end_ticket VARCHAR(80) NOT NULL,
        allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chapter_allocations_chapter_id (chapter_id),
        KEY idx_chapter_allocations_admin_id (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS ticket_assignments (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        buyer_id INT(11) NOT NULL,
        ticket_number VARCHAR(80) NOT NULL,
        assignment_mode VARCHAR(20) NOT NULL,
        assigned_by_admin_id INT(11) NULL,
        assignment_status VARCHAR(20) NOT NULL DEFAULT 'assigned',
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ticket_assignments_ticket_number (ticket_number),
        KEY idx_ticket_assignments_buyer_id (buyer_id),
        CONSTRAINT fk_ticket_assignments_buyer
            FOREIGN KEY (buyer_id) REFERENCES buyers(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ticket_current_number_prefix(): string
{
    return 'PSMECPMXWEN';
}

function getTicketNumberPrefix(mysqli $conn): string
{
    return ticket_current_number_prefix();
}

function formatTicketNumber(string $prefix, int $number): string
{
    return $prefix . '_' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

function buyerStatusLabel(string $status): string
{
    $normalized = strtolower($status);
    if ($normalized === 'email_sent') {
        return 'email sent';
    }
    if ($normalized === 'partially_activated') {
        return 'partially activated';
    }
    if ($normalized === 'fully_activated') {
        return 'fully activated';
    }
    if ($normalized === 'activated') {
        return 'activated';
    }

    return 'not sent (pending)';
}

function buyerFetchActivationCounts(mysqli $conn, int $buyerId): array
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total_assigned,
            SUM(CASE WHEN LOWER(COALESCE(t.status, '')) = 'activated' THEN 1 ELSE 0 END) AS total_activated
        FROM ticket_assignments ta
        LEFT JOIN tickets t ON t.ticket_number = ta.ticket_number
        WHERE ta.buyer_id = ?");
    if (!$stmt) {
        return ['total_assigned' => 0, 'total_activated' => 0];
    }

    $stmt->bind_param('i', $buyerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return [
        'total_assigned' => (int) ($row['total_assigned'] ?? 0),
        'total_activated' => (int) ($row['total_activated'] ?? 0),
    ];
}

function buyerDetermineStatus(array $counts, bool $emailSent = false): string
{
    $totalAssigned = (int) ($counts['total_assigned'] ?? 0);
    $totalActivated = (int) ($counts['total_activated'] ?? 0);

    if ($totalAssigned > 0 && $totalActivated >= $totalAssigned) {
        return 'fully_activated';
    }
    if ($totalActivated > 0) {
        return 'partially_activated';
    }
    if ($emailSent) {
        return 'email_sent';
    }

    return 'pending';
}

function buyerSyncStatus(mysqli $conn, int $buyerId): string
{
    $counts = buyerFetchActivationCounts($conn, $buyerId);

    $buyerStmt = $conn->prepare('SELECT email_sent_at FROM buyers WHERE id = ? LIMIT 1');
    if (!$buyerStmt) {
        return buyerDetermineStatus($counts, false);
    }

    $buyerStmt->bind_param('i', $buyerId);
    $buyerStmt->execute();
    $buyerResult = $buyerStmt->get_result();
    $buyer = $buyerResult ? $buyerResult->fetch_assoc() : null;
    $buyerStmt->close();

    $status = buyerDetermineStatus($counts, !empty($buyer['email_sent_at']));
    $activationCompletedAt = $status === 'fully_activated' ? date('Y-m-d H:i:s') : null;

    $updateStmt = $conn->prepare('UPDATE buyers SET status = ?, activation_completed_at = ?, updated_at = NOW() WHERE id = ?');
    if ($updateStmt) {
        $updateStmt->bind_param('ssi', $status, $activationCompletedAt, $buyerId);
        $updateStmt->execute();
        $updateStmt->close();
    }

    return $status;
}
