<?php

require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';

if (!function_exists('onsite_default_payment_info')) {
    function onsite_default_payment_info(): string
    {
        return "GCash: 0917-123-4567\nBank: BDO - 1234567890 (PSME Inc.)";
    }
}

if (!function_exists('onsite_add_column_if_missing')) {
    function onsite_add_column_if_missing(mysqli $conn, string $tableName, string $columnDefinition): void
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

if (!function_exists('onsite_ensure_schema')) {
    function onsite_ensure_schema(mysqli $conn): void
    {
        ensureBuyerSchema($conn);

        $conn->query("CREATE TABLE IF NOT EXISTS ticket_purchase_requests (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            contact_number VARCHAR(50) NOT NULL,
            chapter VARCHAR(100) NOT NULL,
            ticket_quantity INT(11) NOT NULL,
            proof_of_payment_url VARCHAR(1024) NOT NULL,
            drive_file_id VARCHAR(255) NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            approved_by_admin_id INT(11) NULL,
            assigned_tickets_range VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ticket_purchase_status_created (status, created_at),
            KEY idx_ticket_purchase_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        onsite_add_column_if_missing($conn, 'ticket_purchase_requests', "drive_file_id VARCHAR(255) NULL");
        onsite_add_column_if_missing($conn, 'ticket_purchase_requests', "approved_by_admin_id INT(11) NULL");
        onsite_add_column_if_missing($conn, 'ticket_purchase_requests', "assigned_tickets_range VARCHAR(255) NULL");
        onsite_add_column_if_missing($conn, 'ticket_purchase_requests', "rejection_reason VARCHAR(255) NULL");
        onsite_add_column_if_missing($conn, 'ticket_purchase_requests', "buyer_id INT(11) NULL");

        $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $defaultValue = onsite_default_payment_info();
        $seed = $conn->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
        if ($seed) {
            $key = 'onsite_payment_info';
            $seed->bind_param('ss', $key, $defaultValue);
            $seed->execute();
            $seed->close();
        }
    }
}

if (!function_exists('onsite_get_setting')) {
    function onsite_get_setting(mysqli $conn, string $key, string $defaultValue = ''): string
    {
        onsite_ensure_schema($conn);

        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            return $defaultValue;
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return $defaultValue;
        }

        return (string) ($row['setting_value'] ?? $defaultValue);
    }
}

if (!function_exists('onsite_set_setting')) {
    function onsite_set_setting(mysqli $conn, string $key, string $value): void
    {
        onsite_ensure_schema($conn);

        $stmt = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if (!$stmt) {
            throw new RuntimeException('Unable to save system settings.');
        }

        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('onsite_get_payment_info')) {
    function onsite_get_payment_info(mysqli $conn): string
    {
        $value = onsite_get_setting($conn, 'onsite_payment_info', onsite_default_payment_info());
        return trim($value) !== '' ? $value : onsite_default_payment_info();
    }
}

if (!function_exists('onsite_payment_image_relative_dir')) {
    function onsite_payment_image_relative_dir(): string
    {
        return 'uploads/payment_options';
    }
}

if (!function_exists('onsite_payment_image_absolute_dir')) {
    function onsite_payment_image_absolute_dir(): string
    {
        return __DIR__ . '/' . onsite_payment_image_relative_dir();
    }
}

if (!function_exists('onsite_payment_image_url_from_path')) {
    function onsite_payment_image_url_from_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        return '/CPMraffle/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('onsite_set_payment_image_paths')) {
    function onsite_set_payment_image_paths(mysqli $conn, array $paths): void
    {
        $normalized = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        onsite_set_setting($conn, 'onsite_payment_image_paths', json_encode($normalized));
    }
}

if (!function_exists('onsite_get_payment_image_paths')) {
    function onsite_get_payment_image_paths(mysqli $conn): array
    {
        $pathsRaw = trim(onsite_get_setting($conn, 'onsite_payment_image_paths', ''));
        $paths = [];

        if ($pathsRaw !== '') {
            $decoded = json_decode($pathsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $value = trim((string) $item);
                    if ($value !== '' && !in_array($value, $paths, true)) {
                        $paths[] = $value;
                    }
                }
            } else {
                $single = trim($pathsRaw);
                if ($single !== '') {
                    $paths[] = $single;
                }
            }
        }

        // Backward compatibility: migrate legacy single-path setting.
        if ($paths === []) {
            $legacy = trim(onsite_get_setting($conn, 'onsite_payment_image_path', ''));
            if ($legacy !== '') {
                $paths[] = $legacy;
                onsite_set_payment_image_paths($conn, $paths);
            }
        }

        return $paths;
    }
}

if (!function_exists('onsite_get_payment_image_urls')) {
    function onsite_get_payment_image_urls(mysqli $conn): array
    {
        $urls = [];
        foreach (onsite_get_payment_image_paths($conn) as $path) {
            $url = onsite_payment_image_url_from_path($path);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}

if (!function_exists('onsite_get_payment_image_path')) {
    function onsite_get_payment_image_path(mysqli $conn): string
    {
        $paths = onsite_get_payment_image_paths($conn);
        return $paths !== [] ? (string) $paths[0] : '';
    }
}

if (!function_exists('onsite_get_payment_image_url')) {
    function onsite_get_payment_image_url(mysqli $conn): string
    {
        return onsite_payment_image_url_from_path(onsite_get_payment_image_path($conn));
    }
}

if (!function_exists('onsite_remove_payment_image_file')) {
    function onsite_remove_payment_image_file(string $storedPath): void
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return;
        }

        $absolutePath = __DIR__ . '/' . ltrim($storedPath, '/\\');
        $storageRoot = realpath(onsite_payment_image_absolute_dir());
        $targetReal = realpath($absolutePath);
        if ($storageRoot === false || $targetReal === false) {
            return;
        }

        if (strpos($targetReal, $storageRoot) !== 0) {
            return;
        }

        if (is_file($targetReal)) {
            @unlink($targetReal);
        }
    }
}

if (!function_exists('onsite_store_payment_image_file')) {
    function onsite_store_payment_image_file(array $upload): string
    {
        $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Payment image upload failed.');
        }

        $tmpName = (string) ($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded payment image is invalid.');
        }

        $size = (int) ($upload['size'] ?? 0);
        $maxSize = 8 * 1024 * 1024;
        if ($size <= 0 || $size > $maxSize) {
            throw new RuntimeException('Payment image must be between 1 byte and 8MB.');
        }

        $originalName = basename((string) ($upload['name'] ?? 'payment-image'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($extension, $allowedExt, true)) {
            throw new RuntimeException('Unsupported payment image type. Allowed: ' . implode(', ', $allowedExt) . '.');
        }

        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = (string) finfo_file($finfo, $tmpName);
                finfo_close($finfo);
            }
        }
        $allowedMimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif'];
        if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimes, true)) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $storageDir = onsite_payment_image_absolute_dir();
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Unable to create payment image storage directory.');
        }

        $storedFilename = 'payment_options_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $targetPath = $storageDir . '/' . $storedFilename;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Unable to save payment image.');
        }

        return onsite_payment_image_relative_dir() . '/' . $storedFilename;
    }
}

if (!function_exists('onsite_add_payment_image')) {
    function onsite_add_payment_image(mysqli $conn, array $upload): string
    {
        onsite_ensure_schema($conn);

        $newPath = onsite_store_payment_image_file($upload);
        $paths = onsite_get_payment_image_paths($conn);
        $paths[] = $newPath;
        onsite_set_payment_image_paths($conn, $paths);

        return $newPath;
    }
}

if (!function_exists('onsite_replace_payment_images')) {
    function onsite_replace_payment_images(mysqli $conn, array $upload): string
    {
        onsite_ensure_schema($conn);

        $existing = onsite_get_payment_image_paths($conn);
        foreach ($existing as $oldPath) {
            onsite_remove_payment_image_file((string) $oldPath);
        }

        $newPath = onsite_store_payment_image_file($upload);
        onsite_set_payment_image_paths($conn, [$newPath]);

        return $newPath;
    }
}

if (!function_exists('onsite_remove_payment_image')) {
    function onsite_remove_payment_image(mysqli $conn, string $pathToRemove): void
    {
        onsite_ensure_schema($conn);

        $pathToRemove = trim($pathToRemove);
        if ($pathToRemove === '') {
            return;
        }

        $paths = onsite_get_payment_image_paths($conn);
        $kept = [];
        foreach ($paths as $path) {
            $normalizedPath = trim((string) $path);
            if ($normalizedPath === '') {
                continue;
            }

            if ($normalizedPath === $pathToRemove) {
                onsite_remove_payment_image_file($normalizedPath);
                continue;
            }

            $kept[] = $normalizedPath;
        }

        onsite_set_payment_image_paths($conn, $kept);
    }
}

if (!function_exists('onsite_save_payment_image')) {
    function onsite_save_payment_image(mysqli $conn, array $upload): string
    {
        return onsite_replace_payment_images($conn, $upload);
    }
}

if (!function_exists('onsite_clear_payment_image')) {
    function onsite_clear_payment_image(mysqli $conn): void
    {
        onsite_clear_payment_images($conn);
    }
}

if (!function_exists('onsite_clear_payment_images')) {
    function onsite_clear_payment_images(mysqli $conn): void
    {
        onsite_ensure_schema($conn);

        $existing = onsite_get_payment_image_paths($conn);
        foreach ($existing as $oldPath) {
            onsite_remove_payment_image_file((string) $oldPath);
        }
        onsite_set_payment_image_paths($conn, []);
        onsite_set_setting($conn, 'onsite_payment_image_path', '');
    }
}

if (!function_exists('onsite_create_purchase_request')) {
    function onsite_create_purchase_request(mysqli $conn, array $data): int
    {
        onsite_ensure_schema($conn);

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $contactNumber = trim((string) ($data['contact_number'] ?? ''));
        $chapter = trim((string) ($data['chapter'] ?? ''));
        $ticketQuantity = (int) ($data['ticket_quantity'] ?? 0);
        $proofUrl = trim((string) ($data['proof_of_payment_url'] ?? ''));
        $driveFileId = trim((string) ($data['drive_file_id'] ?? ''));
        if ($name === '' || $email === '' || $contactNumber === '' || $chapter === '') {
            throw new RuntimeException('All buyer fields are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if (!preg_match('/^[0-9+\-() ]{7,30}$/', $contactNumber)) {
            throw new RuntimeException('Please enter a valid contact number.');
        }
        if ($ticketQuantity <= 0 || $ticketQuantity > 1000) {
            throw new RuntimeException('Ticket quantity must be between 1 and 1000.');
        }
        if ($proofUrl === '') {
            throw new RuntimeException('Proof of payment URL is required.');
        }

        if ($driveFileId === '') {
            $driveFileId = null;
        }

        $stmt = $conn->prepare('INSERT INTO ticket_purchase_requests (name, email, contact_number, chapter, ticket_quantity, proof_of_payment_url, drive_file_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "pending")');
        if (!$stmt) {
            throw new RuntimeException('Unable to save purchase request.');
        }

        $stmt->bind_param('ssssiss', $name, $email, $contactNumber, $chapter, $ticketQuantity, $proofUrl, $driveFileId);
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }
}

if (!function_exists('onsite_parse_starting_ticket_number')) {
    function onsite_parse_starting_ticket_number(string $input, string $prefix): int
    {
        $value = trim($input);
        if ($value === '') {
            throw new RuntimeException('Starting ticket number is required.');
        }

        if (preg_match('/^\d+$/', $value)) {
            $number = (int) $value;
            if ($number <= 0) {
                throw new RuntimeException('Starting ticket number must be greater than zero.');
            }
            return $number;
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '[-_](\d+)$/i';
        if (!preg_match($pattern, $value, $matches)) {
            throw new RuntimeException('Enter a numeric start like 12000 or a full ticket like ' . $prefix . '_12000.');
        }

        $number = (int) ($matches[1] ?? 0);
        if ($number <= 0) {
            throw new RuntimeException('Starting ticket number must be greater than zero.');
        }

        return $number;
    }
}

if (!function_exists('onsite_generate_ticket_numbers')) {
    function onsite_generate_ticket_numbers(string $prefix, int $startNumber, int $quantity): array
    {
        if ($startNumber <= 0 || $quantity <= 0) {
            return [];
        }

        $numbers = [];
        for ($i = 0; $i < $quantity; $i++) {
            $numbers[] = formatTicketNumber($prefix, $startNumber + $i);
        }

        return $numbers;
    }
}

if (!function_exists('onsite_validate_tickets_available')) {
    function onsite_validate_tickets_available(mysqli $conn, array $ticketNumbers): void
    {
        if ($ticketNumbers === []) {
            throw new RuntimeException('No tickets were generated for approval.');
        }

        $placeholders = implode(',', array_fill(0, count($ticketNumbers), '?'));
        $sql = "SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number IN ($placeholders)
              AND ta.ticket_number IS NULL
              AND LOWER(COALESCE(t.status, 'pending')) = 'pending'
            FOR UPDATE";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to validate ticket availability.');
        }

        $types = str_repeat('s', count($ticketNumbers));
        $bindValues = [$types];
        foreach ($ticketNumbers as $index => $ticketNumber) {
            $bindValues[] = &$ticketNumbers[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);

        $stmt->execute();
        $result = $stmt->get_result();
        $available = [];
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }
            $available[] = (string) ($row['ticket_number'] ?? '');
        }
        $stmt->close();

        if (count($available) !== count($ticketNumbers)) {
            $missing = array_values(array_diff($ticketNumbers, $available));
            $preview = implode(', ', array_slice($missing, 0, 5));
            throw new RuntimeException('Some tickets are unavailable or already assigned: ' . $preview);
        }
    }
}

if (!function_exists('onsite_fetch_purchase_request')) {
    function onsite_fetch_purchase_request(mysqli $conn, int $requestId): ?array
    {
        onsite_ensure_schema($conn);

        $stmt = $conn->prepare('SELECT * FROM ticket_purchase_requests WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('onsite_approve_purchase_request')) {
    function onsite_approve_purchase_request(mysqli $conn, array $appConfig, int $requestId, string $startingTicketInput, int $approverId): array
    {
        onsite_ensure_schema($conn);

        if ($requestId <= 0) {
            throw new RuntimeException('Invalid purchase request id.');
        }
        if ($approverId <= 0) {
            throw new RuntimeException('Approver account is required.');
        }

        $ticketPrefix = getTicketNumberPrefix($conn);
        $startNumber = onsite_parse_starting_ticket_number($startingTicketInput, $ticketPrefix);

        $buyerId = 0;
        $ticketNumbers = [];
        $buyerForMail = [];

        $conn->begin_transaction();
        try {
            $lockStmt = $conn->prepare('SELECT * FROM ticket_purchase_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$lockStmt) {
                throw new RuntimeException('Unable to lock purchase request.');
            }
            $lockStmt->bind_param('i', $requestId);
            $lockStmt->execute();
            $request = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();

            if (!is_array($request)) {
                throw new RuntimeException('Purchase request not found.');
            }
            $status = strtolower((string) ($request['status'] ?? 'pending'));
            if ($status !== 'pending') {
                throw new RuntimeException('Only pending requests can be approved.');
            }

            $quantity = (int) ($request['ticket_quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > 1000) {
                throw new RuntimeException('Invalid requested ticket quantity.');
            }

            $ticketNumbers = onsite_generate_ticket_numbers($ticketPrefix, $startNumber, $quantity);
            onsite_validate_tickets_available($conn, $ticketNumbers);

            $buyerType = 'individual';
            $fullName = trim((string) ($request['name'] ?? ''));
            $email = trim((string) ($request['email'] ?? ''));
            $contact = trim((string) ($request['contact_number'] ?? ''));
            $chapter = trim((string) ($request['chapter'] ?? ''));

            $insertBuyer = $conn->prepare('INSERT INTO buyers (buyer_type, full_name, email, contact, chapter, status) VALUES (?, ?, ?, ?, ?, "pending")');
            if (!$insertBuyer) {
                throw new RuntimeException('Unable to create buyer record.');
            }
            $insertBuyer->bind_param('sssss', $buyerType, $fullName, $email, $contact, $chapter);
            $insertBuyer->execute();
            $buyerId = (int) $insertBuyer->insert_id;
            $insertBuyer->close();

            $buyerForMail = [
                'id' => $buyerId,
                'full_name' => $fullName,
                'email' => $email,
                'chapter' => $chapter,
                'buyer_type' => $buyerType,
            ];

            $assignmentMode = 'onsite';
            $insertAssignment = $conn->prepare('INSERT INTO ticket_assignments (buyer_id, ticket_number, assignment_mode, assigned_by_admin_id, assignment_status) VALUES (?, ?, ?, ?, "assigned")');
            if (!$insertAssignment) {
                throw new RuntimeException('Unable to assign tickets.');
            }
            foreach ($ticketNumbers as $ticketNumber) {
                $insertAssignment->bind_param('issi', $buyerId, $ticketNumber, $assignmentMode, $approverId);
                if (!$insertAssignment->execute()) {
                    throw new RuntimeException('Failed to assign ticket: ' . $ticketNumber);
                }
            }
            $insertAssignment->close();

            $assignedRange = $ticketNumbers[0] . ' to ' . $ticketNumbers[count($ticketNumbers) - 1];
            $approveStmt = $conn->prepare('UPDATE ticket_purchase_requests
                SET status = "approved", approved_by_admin_id = ?, assigned_tickets_range = ?, buyer_id = ?, rejection_reason = NULL, updated_at = NOW()
                WHERE id = ?');
            if (!$approveStmt) {
                throw new RuntimeException('Unable to update purchase request status.');
            }
            $approveStmt->bind_param('isii', $approverId, $assignedRange, $buyerId, $requestId);
            $approveStmt->execute();
            $approveStmt->close();

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $mailSent = false;
        $mailError = '';
        $deliveryMethod = '';
        if ($buyerId > 0 && $buyerForMail !== [] && $ticketNumbers !== []) {
            try {
                $sendResult = ticket_send_buyer_assignment_email($conn, $appConfig, $buyerForMail, $ticketNumbers);
                $mailSent = true;
                $deliveryMethod = (string) ($sendResult['delivery_method'] ?? 'Gmail API');

                $newStatus = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
                $markSent = $conn->prepare('UPDATE buyers SET status = ?, email_sent_at = NOW(), updated_at = NOW() WHERE id = ?');
                if ($markSent) {
                    $markSent->bind_param('si', $newStatus, $buyerId);
                    $markSent->execute();
                    $markSent->close();
                }
            } catch (Throwable $mailException) {
                $mailError = $mailException->getMessage();
            }
        }

        return [
            'request_id' => $requestId,
            'buyer_id' => $buyerId,
            'mail_sent' => $mailSent,
            'mail_error' => $mailError,
            'delivery_method' => $deliveryMethod,
        ];
    }
}

if (!function_exists('onsite_reject_purchase_request')) {
    function onsite_reject_purchase_request(mysqli $conn, int $requestId, int $approverId, string $rejectionReason): void
    {
        onsite_ensure_schema($conn);

        if ($requestId <= 0) {
            throw new RuntimeException('Invalid purchase request id.');
        }

        $rejectionReason = trim($rejectionReason);
        if ($rejectionReason === '') {
            throw new RuntimeException('Rejection reason is required.');
        }
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($rejectionReason) : strlen($rejectionReason);
        if ($reasonLength > 255) {
            throw new RuntimeException('Rejection reason must be 255 characters or fewer.');
        }

        $stmt = $conn->prepare('UPDATE ticket_purchase_requests
            SET status = "rejected", approved_by_admin_id = ?, rejection_reason = ?, buyer_id = NULL, assigned_tickets_range = NULL, updated_at = NOW()
            WHERE id = ? AND status = "pending"');
        if (!$stmt) {
            throw new RuntimeException('Unable to reject purchase request.');
        }

        $stmt->bind_param('isi', $approverId, $rejectionReason, $requestId);
        $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            throw new RuntimeException('Only pending requests can be rejected.');
        }
    }
}

if (!function_exists('onsite_send_rejection_email')) {
    function onsite_send_rejection_email(mysqli $conn, array $appConfig, array $request): array
    {
        onsite_ensure_schema($conn);

        $toName = trim((string) ($request['name'] ?? ''));
        $toEmail = trim((string) ($request['email'] ?? ''));
        $rejectionReason = trim((string) ($request['rejection_reason'] ?? ''));
        $chapter = trim((string) ($request['chapter'] ?? ''));
        $quantity = (int) ($request['ticket_quantity'] ?? 0);
        $mailConfig = $appConfig['mail'] ?? [];
        $appName = trim((string) ($mailConfig['from_name'] ?? 'CPM Raffle Team'));

        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Request does not have a valid buyer email address.');
        }
        if ($rejectionReason === '') {
            throw new RuntimeException('Missing rejection reason for this request.');
        }

        $safeName = htmlspecialchars($toName !== '' ? $toName : 'Buyer', ENT_QUOTES, 'UTF-8');
        $safeChapter = htmlspecialchars($chapter, ENT_QUOTES, 'UTF-8');
        $safeReason = nl2br(htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8'));

        $subject = 'Update on your onsite raffle ticket purchase request';
        $body = '<p>Hello ' . $safeName . ',</p>'
            . '<p>Thank you for your onsite purchase request. Unfortunately, your request was not approved at this time.</p>'
            . '<p><strong>Request details:</strong></p>'
            . '<ul>'
            . '<li><strong>Chapter:</strong> ' . ($safeChapter !== '' ? $safeChapter : '-') . '</li>'
            . '<li><strong>Ticket quantity:</strong> ' . (int) $quantity . '</li>'
            . '</ul>'
            . '<p><strong>Reason:</strong><br>' . $safeReason . '</p>'
            . '<p>If you have questions, please contact support or submit a new request with updated details.</p>'
            . '<p>Regards,<br>' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</p>';

        $deliveryMethod = ticket_send_gmail_message($appConfig, $toName, $toEmail, [
            'subject' => $subject,
            'body' => $body,
            'format' => 'html',
            'content_type' => 'text/html; charset=UTF-8',
        ]);

        return [
            'delivery_method' => $deliveryMethod,
            'to_email' => $toEmail,
            'request_id' => (int) ($request['id'] ?? 0),
        ];
    }
}

if (!function_exists('onsite_resend_approval_email')) {
    function onsite_resend_approval_email(mysqli $conn, array $appConfig, int $requestId): array
    {
        onsite_ensure_schema($conn);

        if ($requestId <= 0) {
            throw new RuntimeException('Invalid purchase request id.');
        }

        $request = onsite_fetch_purchase_request($conn, $requestId);
        if (!is_array($request)) {
            throw new RuntimeException('Purchase request not found.');
        }
        if (strtolower((string) ($request['status'] ?? '')) !== 'approved') {
            throw new RuntimeException('Only approved requests can resend approval emails.');
        }

        $buyerId = (int) ($request['buyer_id'] ?? 0);
        if ($buyerId <= 0) {
            throw new RuntimeException('Approved request is missing its linked buyer record.');
        }

        $buyerStmt = $conn->prepare('SELECT id, full_name, email, chapter, buyer_type FROM buyers WHERE id = ? LIMIT 1');
        if (!$buyerStmt) {
            throw new RuntimeException('Unable to load buyer details for resend.');
        }
        $buyerStmt->bind_param('i', $buyerId);
        $buyerStmt->execute();
        $buyerResult = $buyerStmt->get_result();
        $buyer = $buyerResult ? $buyerResult->fetch_assoc() : null;
        $buyerStmt->close();

        if (!is_array($buyer)) {
            throw new RuntimeException('Linked buyer record no longer exists.');
        }

        $ticketsStmt = $conn->prepare('SELECT ticket_number FROM ticket_assignments WHERE buyer_id = ? ORDER BY CAST(SUBSTRING_INDEX(ticket_number, "-", -1) AS UNSIGNED), ticket_number ASC');
        if (!$ticketsStmt) {
            throw new RuntimeException('Unable to load assigned ticket numbers.');
        }
        $ticketsStmt->bind_param('i', $buyerId);
        $ticketsStmt->execute();
        $ticketsResult = $ticketsStmt->get_result();

        $ticketNumbers = [];
        while ($row = $ticketsResult ? $ticketsResult->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }

            $ticketNumber = trim((string) ($row['ticket_number'] ?? ''));
            if ($ticketNumber !== '') {
                $ticketNumbers[] = $ticketNumber;
            }
        }
        $ticketsStmt->close();

        if ($ticketNumbers === []) {
            throw new RuntimeException('No assigned tickets found for this approved request.');
        }

        $sendResult = ticket_send_buyer_assignment_email($conn, $appConfig, $buyer, $ticketNumbers);

        $newStatus = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
        $markSent = $conn->prepare('UPDATE buyers SET status = ?, email_sent_at = NOW(), updated_at = NOW() WHERE id = ?');
        if ($markSent) {
            $markSent->bind_param('si', $newStatus, $buyerId);
            $markSent->execute();
            $markSent->close();
        }

        return [
            'request_id' => $requestId,
            'buyer_id' => $buyerId,
            'delivery_method' => (string) ($sendResult['delivery_method'] ?? 'Gmail API'),
            'ticket_count' => count($ticketNumbers),
        ];
    }
}

if (!function_exists('onsite_resend_rejection_email')) {
    function onsite_resend_rejection_email(mysqli $conn, array $appConfig, int $requestId): array
    {
        onsite_ensure_schema($conn);

        if ($requestId <= 0) {
            throw new RuntimeException('Invalid purchase request id.');
        }

        $request = onsite_fetch_purchase_request($conn, $requestId);
        if (!is_array($request)) {
            throw new RuntimeException('Purchase request not found.');
        }
        if (strtolower((string) ($request['status'] ?? '')) !== 'rejected') {
            throw new RuntimeException('Only rejected requests can resend rejection emails.');
        }

        return onsite_send_rejection_email($conn, $appConfig, $request);
    }
}
