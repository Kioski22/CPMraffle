<?php

require_once __DIR__ . '/buyer_schema.php';

if (!function_exists('ticket_activation_token_ttl')) {
    function ticket_activation_token_ttl(array $appConfig): int
    {
        $seconds = (int) (($appConfig['activation_portal']['token_ttl_seconds'] ?? 604800));
        return $seconds > 0 ? $seconds : 604800;
    }
}

if (!function_exists('ticket_activation_portal_url')) {
    function ticket_activation_portal_url(array $appConfig, string $accessToken): string
    {
        $baseUrl = trim((string) ($appConfig['activation_portal']['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = 'https://icto2.psmeinc.org.ph/CPMraffle/verification.php';
        }

        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        return $baseUrl . $separator . 'access=' . rawurlencode($accessToken);
    }
}

if (!function_exists('ticket_issue_buyer_activation_access')) {
    function ticket_issue_buyer_activation_access(mysqli $conn, array $appConfig, int $buyerId): array
    {
        ensureBuyerSchema($conn);

        $selector = bin2hex(random_bytes(8));
        $verifier = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $verifier);
        $expiresAt = date('Y-m-d H:i:s', time() + ticket_activation_token_ttl($appConfig));

        $stmt = $conn->prepare('UPDATE buyers
            SET activation_selector = ?,
                activation_token_hash = ?,
                activation_token_expires_at = ?,
                activation_portal_sent_at = NOW(),
                updated_at = NOW()
            WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to issue activation access token.');
        }

        $stmt->bind_param('sssi', $selector, $tokenHash, $expiresAt, $buyerId);
        $stmt->execute();
        $stmt->close();

        return [
            'access_token' => $selector . '.' . $verifier,
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('ticket_find_buyer_by_activation_access')) {
    function ticket_find_buyer_by_activation_access(mysqli $conn, string $accessToken): ?array
    {
        ensureBuyerSchema($conn);

        $accessToken = trim($accessToken);
        if (!preg_match('/^([a-f0-9]{16})\.([a-f0-9]{64})$/i', $accessToken, $matches)) {
            return null;
        }

        $selector = strtolower($matches[1]);
        $verifier = strtolower($matches[2]);

        $stmt = $conn->prepare('SELECT * FROM buyers WHERE activation_selector = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $result = $stmt->get_result();
        $buyer = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$buyer) {
            return null;
        }
        if (empty($buyer['activation_token_hash']) || !hash_equals((string) $buyer['activation_token_hash'], hash('sha256', $verifier))) {
            return null;
        }
        if (!empty($buyer['activation_token_expires_at']) && strtotime((string) $buyer['activation_token_expires_at']) < time()) {
            return null;
        }

        return $buyer;
    }
}

if (!function_exists('ticket_mark_buyer_activation_access_used')) {
    function ticket_mark_buyer_activation_access_used(mysqli $conn, int $buyerId): void
    {
        $stmt = $conn->prepare('UPDATE buyers SET activation_token_last_used_at = NOW(), updated_at = NOW() WHERE id = ?');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $buyerId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ticket_fetch_buyer_assigned_tickets')) {
    function ticket_fetch_buyer_assigned_tickets(mysqli $conn, int $buyerId): array
    {
        $stmt = $conn->prepare("SELECT ta.ticket_number,
                ta.assignment_status,
                LOWER(COALESCE(t.status, 'pending')) AS ticket_status,
                t.full_name,
                t.email,
                t.contact_number,
                t.verification_link,
                t.instruction_link
            FROM ticket_assignments ta
            LEFT JOIN tickets t ON t.ticket_number = ta.ticket_number
            WHERE ta.buyer_id = ?
            ORDER BY ta.ticket_number ASC");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $buyerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $tickets[] = $row;
        }
        $stmt->close();

        return $tickets;
    }
}

if (!function_exists('ticket_sync_buyer_ticket_activation_links')) {
    function ticket_sync_buyer_ticket_activation_links(mysqli $conn, int $buyerId, string $activationLink): void
    {
        $ticketUpdate = $conn->prepare("UPDATE tickets t
            INNER JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            SET t.verification_link = ?
            WHERE ta.buyer_id = ?");
        if (!$ticketUpdate) {
            throw new RuntimeException('Unable to update ticket activation links.');
        }
        $ticketUpdate->bind_param('si', $activationLink, $buyerId);
        $ticketUpdate->execute();
        $ticketUpdate->close();

        $generatedUpdate = $conn->prepare("UPDATE generated_tickets gt
            INNER JOIN ticket_assignments ta ON ta.ticket_number = gt.ticket_number
            SET gt.verification_link = ?
            WHERE ta.buyer_id = ?");
        if ($generatedUpdate) {
            $generatedUpdate->bind_param('si', $activationLink, $buyerId);
            $generatedUpdate->execute();
            $generatedUpdate->close();
        }
    }
}

if (!function_exists('ticket_buyer_activation_link_data')) {
    function ticket_buyer_activation_link_data(mysqli $conn, array $appConfig, int $buyerId, bool $forceRefresh = false): array
    {
        ensureBuyerSchema($conn);

        if ($buyerId <= 0) {
            throw new RuntimeException('Invalid buyer for activation link generation.');
        }

        $existingStmt = $conn->prepare("SELECT b.activation_token_expires_at,
                MAX(NULLIF(TRIM(COALESCE(t.verification_link, '')), '')) AS verification_link
            FROM buyers b
            LEFT JOIN ticket_assignments ta ON ta.buyer_id = b.id
            LEFT JOIN tickets t ON t.ticket_number = ta.ticket_number
            WHERE b.id = ?
            GROUP BY b.id
            LIMIT 1");

        $verificationLink = '';
        $expiresAt = '';
        if ($existingStmt) {
            $existingStmt->bind_param('i', $buyerId);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
            if (is_array($existing)) {
                $verificationLink = trim((string) ($existing['verification_link'] ?? ''));
                $expiresAt = trim((string) ($existing['activation_token_expires_at'] ?? ''));
            }
        }

        $notExpired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) >= time();
        if (!$forceRefresh && $verificationLink !== '' && $notExpired) {
            return [
                'activation_link' => $verificationLink,
                'expires_at' => $expiresAt,
                'issued_new' => false,
            ];
        }

        $accessPayload = ticket_issue_buyer_activation_access($conn, $appConfig, $buyerId);
        $activationLink = ticket_activation_portal_url($appConfig, (string) $accessPayload['access_token']);
        ticket_sync_buyer_ticket_activation_links($conn, $buyerId, $activationLink);

        return [
            'activation_link' => $activationLink,
            'expires_at' => (string) ($accessPayload['expires_at'] ?? ''),
            'issued_new' => true,
        ];
    }
}

if (!function_exists('ticket_format_number_ranges')) {
    function ticket_format_number_ranges(array $ticketNumbers): string
    {
        $numbers = [];
        foreach ($ticketNumbers as $ticketNumber) {
            if (preg_match('/^(.*?)[-_](\d+)$/', (string) $ticketNumber, $matches)) {
                $numbers[] = [
                    'prefix' => $matches[1],
                    'value' => (int) $matches[2],
                    'raw' => (string) $ticketNumber,
                ];
            } else {
                $numbers[] = [
                    'prefix' => '',
                    'value' => null,
                    'raw' => (string) $ticketNumber,
                ];
            }
        }

        $ranges = [];
        $start = null;
        $previous = null;
        foreach ($numbers as $item) {
            if ($item['value'] === null || ($previous !== null && ($item['prefix'] !== $previous['prefix'] || $item['value'] !== $previous['value'] + 1))) {
                if ($start !== null) {
                    $ranges[] = $start['raw'] === $previous['raw'] ? $start['raw'] : ($start['raw'] . ' to ' . $previous['raw']);
                }
                if ($item['value'] === null) {
                    $ranges[] = $item['raw'];
                    $start = null;
                    $previous = null;
                    continue;
                }
                $start = $item;
                $previous = $item;
                continue;
            }

            if ($start === null) {
                $start = $item;
            }
            $previous = $item;
        }

        if ($start !== null && $previous !== null) {
            $ranges[] = $start['raw'] === $previous['raw'] ? $start['raw'] : ($start['raw'] . ' to ' . $previous['raw']);
        }

        return implode(', ', $ranges);
    }
}
