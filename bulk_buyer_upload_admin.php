<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);
$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());
require_once __DIR__ . '/dashboard_sidebar_helper.php';

if (isset($_GET['download_template'])) {
    $templateType = strtolower(trim((string) $_GET['download_template']));

    $headers = [];
    $rows = [];
    $filename = 'buyer_assignment_template.csv';

    if ($templateType === 'range') {
        $filename = 'range_assignment_template.csv';
        $headers = ['buyer_name', 'email_address', 'contact_number', 'chapter', 'ticket_from', 'ticket_to'];
        $rows = [
            ['Juan Dela Cruz', 'juan.delacruz@example.com', '09171234567', 'NCR', '1', '120'],
            ['Maria Santos', 'maria.santos@example.com', '09181234567', 'Central Luzon', '121', '280'],
            ['Carlos Reyes', 'carlos.reyes@example.com', '09191234567', 'CALABARZON', '281', '400'],
        ];
    } elseif ($templateType === 'sequential') {
        $filename = 'sequential_assignment_template.csv';
        $headers = ['full_name', 'email_address', 'contact_number', 'chapter', 'number_of_tickets', 'starting_ticket'];
        $rows = [
            ['Juan Dela Cruz', 'juan.delacruz@example.com', '09171234567', 'NCR', '120', '401'],
            ['Maria Santos', 'maria.santos@example.com', '09181234567', 'Central Luzon', '160', ''],
            ['Carlos Reyes', 'carlos.reyes@example.com', '09191234567', 'CALABARZON', '140', ''],
        ];
    } else {
        http_response_code(400);
        exit('Invalid template type.');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit('Unable to generate template.');
    }

    // No BOM for better compatibility
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';

ensureBuyerSchema($conn);

const BULK_STAGE_KEY = 'bulk_buyer_upload_stage';

if (!function_exists('bulk_normalize_header')) {
    function bulk_normalize_header(string $header): string
    {
        if (strncmp($header, "\xEF\xBB\xBF", 3) === 0) {
            $header = substr($header, 3);
        }
        $header = trim(strtolower($header));
        return str_replace([' ', '-'], '_', $header);
    }
}

if (!function_exists('bulk_header_aliases')) {
    function bulk_header_aliases(): array
    {
        return [
            'full_name' => ['full_name', 'fullname', 'name', 'buyer_name'],
            'email' => ['email', 'email_address', 'e-mail', 'e_mail'],
            'contact' => ['contact', 'contact_no', 'contact_number', 'phone', 'mobile'],
            'chapter' => ['chapter', 'chapter_name'],
            'assignment_mode' => ['assignment_mode', 'mode'],
            'range_start' => ['range_start', 'start', 'start_number', 'ticket_from', 'starting_ticket'],
            'range_end' => ['range_end', 'end', 'end_number', 'ticket_to'],
            'quantity' => ['quantity', 'qty', 'count', 'number_of_tickets', 'num_tickets'],
        ];
    }
}

if (!function_exists('bulk_build_header_map')) {
    function bulk_build_header_map(array $normalizedHeaderRow): array
    {
        $rawMap = array_flip($normalizedHeaderRow);
        $canonicalMap = [];

        foreach (bulk_header_aliases() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $key = bulk_normalize_header($alias);
                if (array_key_exists($key, $rawMap)) {
                    $canonicalMap[$canonical] = $rawMap[$key];
                    break;
                }
            }
        }

        return $canonicalMap;
    }
}

if (!function_exists('bulk_parse_csv_rows')) {
    function bulk_parse_csv_rows(string $tmpPath): array
    {
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('We could not read the uploaded CSV file. Please try again.');
        }

        $headerRow = fgetcsv($handle);
        if (!is_array($headerRow)) {
            fclose($handle);
            throw new RuntimeException('The CSV file appears empty or invalid.');
        }

        $normalized = [];
        foreach ($headerRow as $h) {
            $normalized[] = bulk_normalize_header((string) $h);
        }

        $headerMap = bulk_build_header_map($normalized);
        $required = ['full_name', 'email', 'contact', 'chapter'];
        $missing = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $headerMap)) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            fclose($handle);
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        $errors = [];
        $lineNumber = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $lineNumber++;

            $blank = true;
            foreach ($raw as $cell) {
                if (trim((string) $cell) !== '') {
                    $blank = false;
                    break;
                }
            }
            if ($blank) {
                continue;
            }

            $getCell = static function (string $field) use ($headerMap, $raw): string {
                if (!array_key_exists($field, $headerMap)) {
                    return '';
                }
                $index = $headerMap[$field];
                if (!is_int($index)) {
                    return '';
                }

                return trim((string) ($raw[$index] ?? ''));
            };

            $fullName = $getCell('full_name');
            $email = $getCell('email');
            $contact = $getCell('contact');
            $chapter = $getCell('chapter');
            $mode = array_key_exists('assignment_mode', $headerMap) ? $getCell('assignment_mode') : '';
            $rangeStart = $getCell('range_start');
            $rangeEnd = $getCell('range_end');
            $quantity = array_key_exists('quantity', $headerMap) ? $getCell('quantity') : '';

            if ($fullName === '' || $email === '' || $contact === '' || $chapter === '') {
                $errors[] = 'Line ' . $lineNumber . ': Buyer Name, Email Address, Contact Number, and Chapter are required.';
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Line ' . $lineNumber . ': Email Address is not valid.';
                continue;
            }

            $rows[] = [
                '_csv_line' => $lineNumber,
                'full_name' => $fullName,
                'email' => $email,
                'contact' => $contact,
                'chapter' => $chapter,
                'assignment_mode' => $mode,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'quantity' => $quantity,
            ];
        }

        fclose($handle);

        if (!empty($errors)) {
            throw new RuntimeException('Please review your CSV file: ' . implode(' ', array_slice($errors, 0, 6)));
        }
        if (empty($rows)) {
            throw new RuntimeException('No valid rows were found in the CSV file.');
        }

        return $rows;
    }
}

if (!function_exists('bulk_parse_row_mode')) {
    function bulk_parse_row_mode(array $row): string
    {
        $mode = strtolower(trim((string) ($row['assignment_mode'] ?? '')));
        if (in_array($mode, ['range', 'quantity'], true)) {
            return $mode;
        }

        $hasStart = trim((string) ($row['range_start'] ?? '')) !== '';
        $hasEnd = trim((string) ($row['range_end'] ?? '')) !== '';
        $hasQty = trim((string) ($row['quantity'] ?? '')) !== '';

        if ($hasQty && !$hasEnd) {
            return 'quantity';
        }
        if ($hasStart || $hasEnd) {
            return 'range';
        }

        $csvLine = (int) ($row['_csv_line'] ?? 0);
        throw new RuntimeException('Line ' . $csvLine . ': could not determine assignment type. Fill in either Ticket From/Ticket To, or Number of Tickets.');
    }
}

if (!function_exists('bulk_format_ticket_number')) {
    function bulk_format_ticket_number(string $prefix, int $number): string
    {
        return $prefix . '_' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('bulk_build_row_overrides')) {
    function bulk_build_row_overrides(array $post): array
    {
        $starts = isset($post['row_range_start']) && is_array($post['row_range_start']) ? $post['row_range_start'] : [];
        $ends = isset($post['row_range_end']) && is_array($post['row_range_end']) ? $post['row_range_end'] : [];
        $quantities = isset($post['row_quantity']) && is_array($post['row_quantity']) ? $post['row_quantity'] : [];

        $keys = array_unique(array_merge(array_keys($starts), array_keys($ends), array_keys($quantities)));
        $out = [];
        foreach ($keys as $k) {
            $line = (string) ((int) $k);
            if ($line === '0') {
                continue;
            }
            $out[$line] = [
                'range_start' => trim((string) ($starts[$k] ?? '')),
                'range_end' => trim((string) ($ends[$k] ?? '')),
                'quantity' => trim((string) ($quantities[$k] ?? '')),
            ];
        }

        return $out;
    }
}

if (!function_exists('bulk_build_allocations')) {
    function bulk_build_allocations(array $rows, string $ticketPrefix, array $overrides = []): array
    {
        $allocations = [];
        $prevEnd = null;

        foreach ($rows as $index => $row) {
            $csvLine = (int) ($row['_csv_line'] ?? ($index + 2));
            $lineKey = (string) $csvLine;
            $mode = bulk_parse_row_mode($row);

            $ov = $overrides[$lineKey] ?? [];
            $startRaw = trim((string) ($ov['range_start'] ?? ($row['range_start'] ?? '')));
            $endRaw = trim((string) ($ov['range_end'] ?? ($row['range_end'] ?? '')));
            $qtyRaw = trim((string) ($ov['quantity'] ?? ($row['quantity'] ?? '')));

            $start = null;
            $end = null;
            $qty = null;

            if ($mode === 'range') {
                if ($startRaw === '' || $endRaw === '') {
                    throw new RuntimeException('Line ' . $csvLine . ': Ticket From and Ticket To are required for specific range assignment.');
                }
                if (!ctype_digit($startRaw) || !ctype_digit($endRaw)) {
                    throw new RuntimeException('Line ' . $csvLine . ': Ticket From and Ticket To must be whole numbers.');
                }
                $start = (int) $startRaw;
                $end = (int) $endRaw;
                if ($start <= 0 || $end < $start) {
                    throw new RuntimeException('Line ' . $csvLine . ': Ticket To must be greater than or equal to Ticket From, and both must be greater than 0.');
                }
                $qty = ($end - $start) + 1;
            } else {
                if ($qtyRaw === '' || !ctype_digit($qtyRaw) || (int) $qtyRaw <= 0) {
                    throw new RuntimeException('Line ' . $csvLine . ': Number of Tickets must be a whole number greater than 0.');
                }
                $qty = (int) $qtyRaw;

                if ($prevEnd === null) {
                    if ($startRaw === '' || !ctype_digit($startRaw) || (int) $startRaw <= 0) {
                        throw new RuntimeException('Line ' . $csvLine . ': Starting Ticket is required for the first sequential row.');
                    }
                    $start = (int) $startRaw;
                } else {
                    $start = $prevEnd + 1;
                }
                $end = $start + $qty - 1;
            }

            if ($qty > 1000) {
                throw new RuntimeException('Line ' . $csvLine . ': Number of Tickets cannot exceed 1000 per buyer.');
            }

            $tickets = [];
            for ($n = $start; $n <= $end; $n++) {
                $tickets[] = bulk_format_ticket_number($ticketPrefix, $n);
            }

            $prevEnd = $end;
            $allocations[] = [
                'csv_line' => $csvLine,
                'row' => $row,
                'mode' => $mode,
                'range_start' => $start,
                'range_end' => $end,
                'quantity' => $qty,
                'ticket_numbers' => $tickets,
            ];
        }

        return $allocations;
    }
}

if (!function_exists('bulk_inspect_allocations')) {
    function bulk_inspect_allocations(mysqli $conn, array $allocations): array
    {
        $result = [
            'row_reports' => [],
            'ready_rows' => [],
            'ready_tickets' => [],
            'duplicate_skips' => [],
            'blocking_errors' => [],
        ];

        if (empty($allocations)) {
            return $result;
        }

        $allTickets = [];
        foreach ($allocations as $a) {
            foreach ((array) ($a['ticket_numbers'] ?? []) as $t) {
                $allTickets[] = (string) $t;
            }
        }
        $allTickets = array_values(array_unique($allTickets));
        if (empty($allTickets)) {
            return $result;
        }

        $dupInBatch = [];
        $seen = [];
        foreach ($allocations as $a) {
            foreach ((array) ($a['ticket_numbers'] ?? []) as $t) {
                if (isset($seen[$t])) {
                    $dupInBatch[$t] = true;
                }
                $seen[$t] = true;
            }
        }

        $placeholders = implode(',', array_fill(0, count($allTickets), '?'));
        $sql = "SELECT t.ticket_number,
                LOWER(COALESCE(t.status, '')) AS ticket_status,
                LOWER(COALESCE(b.email, '')) AS assigned_email
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            LEFT JOIN buyers b ON b.id = ta.buyer_id
            WHERE t.ticket_number IN ($placeholders)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to inspect ticket state.');
        }
        $types = str_repeat('s', count($allTickets));
        $bind = [$types];
        foreach ($allTickets as $i => $v) {
            $bind[] = &$allTickets[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $rs = $stmt->get_result();

        $stateMap = [];
        while ($r = $rs->fetch_assoc()) {
            $stateMap[$r['ticket_number']] = [
                'status' => (string) ($r['ticket_status'] ?? ''),
                'assigned_email' => (string) ($r['assigned_email'] ?? ''),
            ];
        }
        $stmt->close();

        foreach ($allocations as $a) {
            $row = (array) ($a['row'] ?? []);
            $csvLine = (int) ($a['csv_line'] ?? 0);
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $tickets = (array) ($a['ticket_numbers'] ?? []);
            $status = 'ready';
            $reason = 'Ready for import.';

            $missing = [];
            $assignedOther = [];
            $assignedSame = [];
            $notPending = [];
            $dupBatch = [];

            foreach ($tickets as $tn) {
                if (isset($dupInBatch[$tn])) {
                    $dupBatch[] = $tn;
                }
                if (!isset($stateMap[$tn])) {
                    $missing[] = $tn;
                    continue;
                }
                $state = $stateMap[$tn];
                $assignedEmail = strtolower(trim((string) ($state['assigned_email'] ?? '')));
                if ($assignedEmail !== '') {
                    if ($assignedEmail === $email) {
                        $assignedSame[] = $tn;
                    } else {
                        $assignedOther[] = $tn;
                    }
                } elseif (strtolower(trim((string) ($state['status'] ?? ''))) !== 'pending') {
                    $notPending[] = $tn;
                }
            }

            if (!empty($missing)) {
                $status = 'error';
                $reason = 'Ticket(s) missing in pool: ' . implode(', ', array_slice($missing, 0, 4));
            } elseif (!empty($dupBatch)) {
                $status = 'error';
                $reason = 'Duplicate ticket(s) inside CSV batch.';
            } elseif (!empty($assignedOther)) {
                $status = 'error';
                $reason = 'Ticket(s) already assigned to another buyer.';
            } elseif (!empty($notPending)) {
                $status = 'error';
                $reason = 'Ticket(s) are not pending.';
            } elseif (count($assignedSame) === count($tickets)) {
                $status = 'skip';
                $reason = 'duplicate ticket instance encoded, skipping this';
            } elseif (!empty($assignedSame)) {
                $status = 'error';
                $reason = 'Partial duplicate assignment for this buyer; adjust range.';
            }

            $rowReport = [
                'csv_line' => $csvLine,
                'full_name' => (string) ($row['full_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'contact' => (string) ($row['contact'] ?? ''),
                'chapter' => (string) ($row['chapter'] ?? ''),
                'mode' => (string) ($a['mode'] ?? 'range'),
                'range_start' => (int) ($a['range_start'] ?? 0),
                'range_end' => (int) ($a['range_end'] ?? 0),
                'quantity' => (int) ($a['quantity'] ?? 0),
                'ticket_preview' => implode(', ', array_slice($tickets, 0, 3)) . (count($tickets) > 3 ? ' ...' : ''),
                'status' => $status,
                'reason' => $reason,
            ];
            $result['row_reports'][] = $rowReport;

            if ($status === 'ready') {
                $result['ready_rows'][] = $a;
                foreach ($tickets as $t) {
                    $result['ready_tickets'][] = $t;
                }
            } elseif ($status === 'skip') {
                $result['duplicate_skips'][] = 'CSV row ' . $csvLine . ': ' . $reason;
            } else {
                $result['blocking_errors'][] = 'CSV row ' . $csvLine . ': ' . $reason;
            }
        }

        return $result;
    }
}

if (!function_exists('bulk_validate_available_tickets')) {
    function bulk_validate_available_tickets(mysqli $conn, array $ticketNumbers): void
    {
        if (empty($ticketNumbers)) {
            throw new RuntimeException('No tickets selected for assignment.');
        }

        $unique = array_values(array_unique($ticketNumbers));
        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $sql = "SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.ticket_number IN ($placeholders)
              AND ta.ticket_number IS NULL
              AND LOWER(t.status) = 'pending'
            FOR UPDATE";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to validate ticket availability.');
        }

        $types = str_repeat('s', count($unique));
        $bind = [$types];
        foreach ($unique as $i => $v) {
            $bind[] = &$unique[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $rs = $stmt->get_result();

        $available = [];
        while ($r = $rs->fetch_assoc()) {
            $available[] = $r['ticket_number'];
        }
        $stmt->close();

        if (count($available) !== count($unique)) {
            $missing = array_values(array_diff($unique, $available));
            throw new RuntimeException('Some tickets are unavailable: ' . implode(', ', array_slice($missing, 0, 6)));
        }
    }
}

$message = '';
$messageType = 'success';
$ticketPrefix = getTicketNumberPrefix($conn);
$previewRows = [];
$previewReady = 0;
$previewSkipped = 0;
$previewBlocked = 0;
$canFinalize = false;

if (isset($_POST['action']) && $_POST['action'] === 'reset_stage') {
    unset($_SESSION[BULK_STAGE_KEY]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    try {
        if ($action === 'upload_preview') {
            if (!isset($_FILES['bulk_csv_file'])) {
                throw new RuntimeException('Please select a CSV file.');
            }
            $uploadError = (int) ($_FILES['bulk_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please select a CSV file.');
            }

            $name = (string) ($_FILES['bulk_csv_file']['name'] ?? '');
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
                throw new RuntimeException('Only CSV files are allowed.');
            }

            $tmp = (string) ($_FILES['bulk_csv_file']['tmp_name'] ?? '');
            $rows = bulk_parse_csv_rows($tmp);

            $_SESSION[BULK_STAGE_KEY] = [
                'rows' => $rows,
            ];

            $overrides = bulk_build_row_overrides($_POST);
            $allocations = bulk_build_allocations($rows, $ticketPrefix, $overrides);
            $inspection = bulk_inspect_allocations($conn, $allocations);
            $previewRows = $inspection['row_reports'];
        } elseif (in_array($action, ['refresh_preview', 'finalize_import'], true)) {
            $stage = $_SESSION[BULK_STAGE_KEY] ?? null;
            if (!is_array($stage) || !isset($stage['rows']) || !is_array($stage['rows']) || empty($stage['rows'])) {
                throw new RuntimeException('No staged CSV data found. Upload a CSV file first.');
            }

            $rows = $stage['rows'];
            $overrides = bulk_build_row_overrides($_POST);
            $allocations = bulk_build_allocations($rows, $ticketPrefix, $overrides);
            $inspection = bulk_inspect_allocations($conn, $allocations);
            $previewRows = $inspection['row_reports'];

            if ($action === 'finalize_import') {
                $readyRows = $inspection['ready_rows'];
                $readyTickets = $inspection['ready_tickets'];
                $blockedRows = $inspection['blocking_errors'];
                $skippedRows = $inspection['duplicate_skips'];

                if (empty($readyRows)) {
                    $message = 'No rows were imported. Blocked: ' . count($blockedRows) . ', skipped: ' . count($skippedRows) . '.';
                    $messageType = 'warning';
                } else {
                    $conn->begin_transaction();
                    bulk_validate_available_tickets($conn, $readyTickets);

                    $adminId = auth_user_id();
                    $insertBuyer = $conn->prepare('INSERT INTO buyers (buyer_type, full_name, email, contact, chapter, status) VALUES (?, ?, ?, ?, ?, "pending")');
                    if (!$insertBuyer) {
                        throw new RuntimeException('Unable to create buyers.');
                    }

                    $insertAssign = $conn->prepare('INSERT INTO ticket_assignments (buyer_id, ticket_number, assignment_mode, assigned_by_admin_id, assignment_status) VALUES (?, ?, ?, ?, "assigned")');
                    if (!$insertAssign) {
                        $insertBuyer->close();
                        throw new RuntimeException('Unable to store ticket assignments.');
                    }

                    $createdBuyers = 0;
                    $createdTickets = 0;
                    $emailPayloads = [];

                    foreach ($readyRows as $allocation) {
                        $row = (array) ($allocation['row'] ?? []);
                        $mode = (string) ($allocation['mode'] ?? 'range');
                        $ticketNumbers = (array) ($allocation['ticket_numbers'] ?? []);
                        $csvLine = (int) ($allocation['csv_line'] ?? 0);

                        $buyerType = 'chapter';
                        $fullName = (string) ($row['full_name'] ?? '');
                        $email = (string) ($row['email'] ?? '');
                        $contact = (string) ($row['contact'] ?? '');
                        $chapter = (string) ($row['chapter'] ?? '');

                        $insertBuyer->bind_param('sssss', $buyerType, $fullName, $email, $contact, $chapter);
                        if (!$insertBuyer->execute()) {
                            throw new RuntimeException('Failed to create buyer for CSV row ' . $csvLine . '.');
                        }

                        $buyerId = (int) $insertBuyer->insert_id;
                        foreach ($ticketNumbers as $ticketNumber) {
                            $insertAssign->bind_param('issi', $buyerId, $ticketNumber, $mode, $adminId);
                            if (!$insertAssign->execute()) {
                                throw new RuntimeException('Failed to assign ticket ' . $ticketNumber . ' for CSV row ' . $csvLine . '.');
                            }
                            $createdTickets++;
                        }

                        $emailPayloads[] = [
                            'id' => $buyerId,
                            'full_name' => $fullName,
                            'email' => $email,
                            'chapter' => $chapter,
                            'buyer_type' => $buyerType,
                            'ticket_numbers' => $ticketNumbers,
                        ];

                        $createdBuyers++;
                    }

                    $insertAssign->close();
                    $insertBuyer->close();
                    $conn->commit();

                    $emailFailures = [];
                    foreach ($emailPayloads as $payload) {
                        try {
                            ticket_send_buyer_assignment_email($conn, $appConfig, [
                                'id' => $payload['id'],
                                'full_name' => $payload['full_name'],
                                'email' => $payload['email'],
                                'chapter' => $payload['chapter'],
                                'buyer_type' => $payload['buyer_type'],
                            ], $payload['ticket_numbers']);

                            $statusAfterEmail = buyerDetermineStatus(buyerFetchActivationCounts($conn, (int) $payload['id']), true);
                            $markSent = $conn->prepare('UPDATE buyers SET status = ?, email_sent_at = NOW(), updated_at = NOW() WHERE id = ?');
                            if ($markSent) {
                                $buyerIdForStatus = (int) $payload['id'];
                                $markSent->bind_param('si', $statusAfterEmail, $buyerIdForStatus);
                                $markSent->execute();
                                $markSent->close();
                            }
                        } catch (Throwable $mailEx) {
                            $emailFailures[] = 'Buyer ID ' . (int) $payload['id'] . ': ' . $mailEx->getMessage();
                        }
                    }

                    $message = 'Bulk import completed. Buyers inserted: ' . $createdBuyers . ', tickets assigned: ' . $createdTickets
                        . ', blocked skipped: ' . count($blockedRows) . ', duplicate skipped: ' . count($skippedRows) . '.';
                    if (!empty($emailFailures)) {
                        $message .= ' Email warnings: ' . implode(' | ', array_slice($emailFailures, 0, 3));
                        if (count($emailFailures) > 3) {
                            $message .= ' (+' . (count($emailFailures) - 3) . ' more)';
                        }
                        $messageType = 'warning';
                    } else {
                        $messageType = (count($blockedRows) > 0 || count($skippedRows) > 0) ? 'warning' : 'success';
                    }

                    unset($_SESSION[BULK_STAGE_KEY]);
                }
            }
        }
    } catch (Throwable $e) {
        @$conn->rollback();
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

if (empty($previewRows)) {
    $stage = $_SESSION[BULK_STAGE_KEY] ?? null;
    if (is_array($stage) && isset($stage['rows']) && is_array($stage['rows']) && !empty($stage['rows'])) {
        try {
            $allocations = bulk_build_allocations($stage['rows'], $ticketPrefix, []);
            $inspection = bulk_inspect_allocations($conn, $allocations);
            $previewRows = $inspection['row_reports'];
        } catch (Throwable $ignore) {
            $previewRows = [];
        }
    }
}

foreach ($previewRows as $row) {
    $status = strtolower((string) ($row['status'] ?? 'ready'));
    if ($status === 'error') {
        $previewBlocked++;
    } elseif ($status === 'skip') {
        $previewSkipped++;
    } else {
        $previewReady++;
    }
}
$canFinalize = $previewReady > 0;

$totalTickets = 0;
$totalTicketsQuery = $conn->query('SELECT COUNT(*) AS total FROM tickets');
if ($totalTicketsQuery instanceof mysqli_result) {
    $totalTickets = (int) ($totalTicketsQuery->fetch_assoc()['total'] ?? 0);
}

$assignedTicketCount = 0;
$assignedTicketQuery = $conn->query('SELECT COUNT(*) AS total FROM ticket_assignments');
if ($assignedTicketQuery instanceof mysqli_result) {
    $assignedTicketCount = (int) ($assignedTicketQuery->fetch_assoc()['total'] ?? 0);
}

$availableCount = 0;
$availableCountQuery = $conn->query("SELECT COUNT(*) AS total
    FROM tickets t
    LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
    WHERE ta.ticket_number IS NULL
      AND LOWER(t.status) = 'pending'");
if ($availableCountQuery instanceof mysqli_result) {
    $availableCount = (int) ($availableCountQuery->fetch_assoc()['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Buyer Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/CPMraffle/css/dashboard_sidebar.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        :root {
            --primary: #283555;
            --secondary: #3a4a6f;
            --accent: #ff6b35;
            --light: #f4f6f9;
            --dark: #2c3e50;
            --white: #ffffff;
            --border-color: #e8eef5;
            --ok: #1f9d55;
            --warn: #b9770e;
            --bad: #c0392b;
        }
        body { background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%); color: var(--dark); }
        .dashboard-container { display: flex; min-height: 100vh; }
        aside.sidebar {
            width: 250px;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            color: white;
            padding: 22px 20px 30px 20px;
        }
        aside.sidebar .sidebar-header h1 { font-size: 16px; color: var(--white); text-transform: uppercase; margin: 0; }
        aside.sidebar nav ul { list-style: none; padding: 0; margin-top: 10px; }
        aside.sidebar nav ul li { margin: 10px 0; }
        aside.sidebar nav ul li a {
            display: flex; align-items: center; color: #f4f6f9; text-decoration: none; font-size: 16px;
            padding: 8px 12px; border-radius: 5px;
        }
        aside.sidebar nav ul li a i { margin-right: 10px; width: 20px; text-align: center; }
        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background: rgba(255, 107, 53, 0.2);
            padding-left: 15px;
            border-left: 3px solid var(--accent);
        }
        main.main-content { flex: 1; margin-left: 280px; padding: 2rem; }
        .card { border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 8px 30px rgba(40,53,85,0.12); }
        .card-header { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); border: none; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
        }
        .stats-tile {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 4px 20px rgba(40,53,85,0.08);
        }
        .stats-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b778c; margin-bottom: 6px; }
        .stats-value { font-size: 26px; font-weight: 700; color: var(--primary); line-height: 1.1; }
        .stats-value.small { font-size: 20px; }
        .step-card {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(40,53,85,0.08);
        }
        .step-card .step-card-header {
            border-bottom: 1px solid var(--border-color);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .step-card .step-card-body { padding: 18px; }
        .step-chip {
            background: rgba(40, 53, 85, 0.1);
            color: var(--primary);
            font-weight: 700;
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .template-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            height: 100%;
        }
        .template-card h6 { margin-bottom: 6px; color: var(--primary); }
        .template-card p { margin-bottom: 10px; color: #56627a; font-size: 14px; }
        .status-pill {
            font-size: 12px;
            font-weight: 700;
            border-radius: 999px;
            padding: 5px 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-transform: none;
        }
        .status-pill.ok { background: #e9f8ef; color: var(--ok); }
        .status-pill.skip { background: #fff5e6; color: var(--warn); }
        .status-pill.bad { background: #fdecea; color: var(--bad); }
        .assignment-badge {
            background: #eef2ff;
            color: #3743a5;
            font-size: 11px;
            letter-spacing: 0.02em;
            padding: 4px 8px;
            border-radius: 999px;
            font-weight: 600;
        }
        .table-preview th {
            white-space: nowrap;
            font-size: 12px;
            text-transform: uppercase;
            color: #5b6780;
        }
        .table-preview td { vertical-align: middle; }
        .auto-note {
            font-size: 12px;
            color: #6b778c;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            aside.sidebar { width: 100%; position: relative; }
            main.main-content { margin-left: 0; padding: 10px; }
            .stats-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/bulk_buyer_upload_admin.php', $isAdmin, $dashboardPath); ?>

    <main class="main-content">
        <div class="container-fluid py-2">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="mb-0">Chapter Buyer Bulk Assignment</h2>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <div class="stats-grid mb-3">
                <div class="stats-tile">
                    <div class="stats-label">Available for Walk-in Buyers</div>
                    <div class="stats-value"><?php echo number_format($availableCount); ?></div>
                </div>
                <div class="stats-tile">
                    <div class="stats-label">Assigned to Chapters</div>
                    <div class="stats-value"><?php echo number_format($assignedTicketCount); ?></div>
                </div>
                <div class="stats-tile">
                    <div class="stats-label">Total Tickets Generated</div>
                    <div class="stats-value"><?php echo number_format($totalTickets); ?></div>
                </div>
                <div class="stats-tile">
                    <div class="stats-label">Ticket Prefix</div>
                    <div class="stats-value small"><?php echo htmlspecialchars($ticketPrefix, ENT_QUOTES); ?></div>
                </div>
            </div>

            <div class="accordion mb-3" id="howItWorksAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="howItWorksHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#howItWorksBody" aria-expanded="false" aria-controls="howItWorksBody">
                            How this works
                        </button>
                    </h2>
                    <div id="howItWorksBody" class="accordion-collapse collapse" aria-labelledby="howItWorksHeading" data-bs-parent="#howItWorksAccordion">
                        <div class="accordion-body">
                            <p class="mb-2">Use this page to reserve ticket numbers for chapter buyers in bulk. You can assign specific ranges or assign sequentially using quantities.</p>
                            <p class="mb-2">Tickets reserved here are automatically skipped when individual walk-in buyers are assigned tickets.</p>
                            <p class="mb-0">Choose a template below, fill it out in Excel, upload it, review the preview, and confirm.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="step-card mb-3">
                <div class="step-card-header">
                    <h5 class="mb-0">Prepare and Upload Buyer List</h5>
                    <span class="step-chip">Step 1</span>
                </div>
                <div class="step-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <div class="template-card">
                                <h6>Specific Range Template</h6>
                                <p>Use when each buyer must receive exact ticket ranges (example: 1-120, 121-280).</p>
                                <a class="btn btn-outline-primary btn-sm" href="?download_template=range">
                                    <i class="fas fa-download me-1"></i> Download Specific Range CSV
                                </a>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="template-card">
                                <h6>Sequential Template</h6>
                                <p>Use when you only know ticket counts. Set Starting Ticket on the first row, next rows continue automatically.</p>
                                <a class="btn btn-outline-primary btn-sm" href="?download_template=sequential">
                                    <i class="fas fa-download me-1"></i> Download Sequential CSV
                                </a>
                            </div>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="action" value="upload_preview">
                        <div class="col-md-8">
                            <label class="form-label">Buyer CSV file</label>
                            <input type="file" name="bulk_csv_file" class="form-control" accept=".csv" required>
                            <small class="text-muted d-block mt-2">Accepted format: .csv</small>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-file-upload me-1"></i> Upload and Review
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="step-card">
                <div class="step-card-header">
                    <h5 class="mb-0">Review and Confirm Assignment</h5>
                    <span class="step-chip">Step 2</span>
                </div>
                <div class="step-card-body">
                    <div class="d-flex gap-2 mb-3 flex-wrap">
                        <span class="badge bg-success">Good to go: <?php echo (int) $previewReady; ?></span>
                        <span class="badge bg-warning text-dark">Already assigned: <?php echo (int) $previewSkipped; ?></span>
                        <span class="badge bg-danger">Needs action: <?php echo (int) $previewBlocked; ?></span>
                    </div>

                    <?php if (empty($previewRows)): ?>
                        <p class="text-muted mb-0">No uploaded data yet. Complete Step 1 first, then review your rows here.</p>
                    <?php else: ?>
                        <form method="POST" id="preview-form">
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-bordered align-middle table-preview">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Buyer Name</th>
                                            <th>Email</th>
                                            <th>Chapter</th>
                                            <th>Assignment Type</th>
                                            <th>Ticket From</th>
                                            <th>Ticket To</th>
                                            <th># Tickets</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php $firstQuantityRow = true; ?>
                                    <?php foreach ($previewRows as $row): ?>
                                        <?php
                                            $status = strtolower((string) ($row['status'] ?? 'ready'));
                                            $csvLine = (int) ($row['csv_line'] ?? 0);
                                            $mode = (string) ($row['mode'] ?? 'range');
                                            $isQuantity = $mode === 'quantity';
                                            $isFirstSequential = $isQuantity && $firstQuantityRow;
                                            if ($isQuantity && $firstQuantityRow) {
                                                $firstQuantityRow = false;
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo $csvLine; ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['chapter'] ?? ''), ENT_QUOTES); ?></td>
                                            <td>
                                                <?php if ($isQuantity): ?>
                                                    <span class="assignment-badge">Sequential</span>
                                                <?php else: ?>
                                                    <span class="assignment-badge">Specific Range</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$isQuantity): ?>
                                                    <input type="number" min="1" class="form-control form-control-sm" name="row_range_start[<?php echo $csvLine; ?>]" value="<?php echo htmlspecialchars((string) ($row['range_start'] ?? ''), ENT_QUOTES); ?>">
                                                <?php elseif ($isFirstSequential): ?>
                                                    <input type="number" min="1" class="form-control form-control-sm" name="row_range_start[<?php echo $csvLine; ?>]" value="<?php echo htmlspecialchars((string) ($row['range_start'] ?? ''), ENT_QUOTES); ?>">
                                                <?php else: ?>
                                                    <span class="auto-note">Auto (follows previous)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$isQuantity): ?>
                                                    <input type="number" min="1" class="form-control form-control-sm" name="row_range_end[<?php echo $csvLine; ?>]" value="<?php echo htmlspecialchars((string) ($row['range_end'] ?? ''), ENT_QUOTES); ?>">
                                                <?php else: ?>
                                                    <input type="number" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) ($row['range_end'] ?? ''), ENT_QUOTES); ?>" readonly>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isQuantity): ?>
                                                    <input type="number" min="1" class="form-control form-control-sm" name="row_quantity[<?php echo $csvLine; ?>]" value="<?php echo htmlspecialchars((string) ($row['quantity'] ?? ''), ENT_QUOTES); ?>">
                                                <?php else: ?>
                                                    <input type="number" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) ($row['quantity'] ?? ''), ENT_QUOTES); ?>" readonly>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($status === 'error'): ?>
                                                    <span class="status-pill bad"><i class="fas fa-times"></i> Issue</span>
                                                <?php elseif ($status === 'skip'): ?>
                                                    <span class="status-pill skip"><i class="fas fa-reply"></i> Already assigned</span>
                                                <?php else: ?>
                                                    <span class="status-pill ok"><i class="fas fa-check"></i> Good to go</span>
                                                <?php endif; ?>
                                                <div class="small text-muted mt-1"><?php echo htmlspecialchars((string) ($row['reason'] ?? ''), ENT_QUOTES); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($previewBlocked > 0): ?>
                                <div class="alert alert-warning py-2 mb-3">Some rows need correction before they can be assigned. You can still confirm now, and only rows marked "Good to go" will be assigned.</div>
                            <?php endif; ?>

                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-outline-primary" name="action" value="refresh_preview">
                                    <i class="fas fa-rotate-right me-1"></i> Update Preview
                                </button>
                                <button type="submit" class="btn btn-primary" name="action" value="finalize_import" <?php echo $canFinalize ? '' : 'disabled'; ?>>
                                    <i class="fas fa-check me-1"></i> Confirm Assignment
                                </button>
                                <button type="submit" class="btn btn-outline-secondary" name="action" value="reset_stage">
                                    <i class="fas fa-trash-can me-1"></i> Start Over
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


