<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat', 'chapter', 'cpmadmin']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';

header('Content-Type: application/json');

function ctr_extract_number(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }
    if (preg_match('/^\d+$/', $trimmed) === 1) {
        return (int) ltrim($trimmed, '0') ?: 0;
    }
    if (preg_match('/^[A-Za-z0-9_]+[-_](\d+)$/', $trimmed, $matches) === 1) {
        return (int) (ltrim($matches[1], '0') ?: 0);
    }
    return 0;
}

function ctr_json(bool $available, string $message, array $conflicts = []): never
{
    echo json_encode([
        'available' => $available,
        'message'   => $message,
        'conflicts' => $conflicts,
    ]);
    exit;
}

$startInput = trim((string) ($_GET['start'] ?? ''));
$endInput   = trim((string) ($_GET['end'] ?? ''));

if ($startInput === '' || $endInput === '') {
    ctr_json(false, 'Start and end values are required.');
}

$startNumber = ctr_extract_number($startInput);
$endNumber   = ctr_extract_number($endInput);

if ($startNumber <= 0 || $endNumber <= 0) {
    ctr_json(false, 'Invalid ticket number format.');
}

if ($endNumber < $startNumber) {
    ctr_json(false, 'End ticket must be greater than or equal to start ticket.');
}

$rangeSize = $endNumber - $startNumber + 1;
if ($rangeSize > 3000) {
    ctr_json(false, 'Range too large (max 3000 tickets per check).');
}

$prefix = ticket_current_number_prefix();

$ticketNumbers = [];
for ($i = $startNumber; $i <= $endNumber; $i++) {
    $ticketNumbers[] = $prefix . '-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
}

$placeholders = implode(',', array_fill(0, count($ticketNumbers), '?'));
$sql = 'SELECT t.ticket_number,
            LOWER(COALESCE(t.status, "")) AS ticket_status,
            COALESCE(t.assigned_chapter_id, 0) AS assigned_chapter_id,
            ta.ticket_number AS assigned_ticket
        FROM tickets t
        LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
        WHERE t.ticket_number IN (' . $placeholders . ')';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    ctr_json(false, 'Database error while checking ticket range.');
}

$types  = str_repeat('s', count($ticketNumbers));
$bind   = [$types];
foreach ($ticketNumbers as $index => $_v) {
    $bind[] = &$ticketNumbers[$index];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();

$found    = [];
$conflicts = [];
while ($result && ($row = $result->fetch_assoc())) {
    $found[] = $row['ticket_number'];
    if (!empty($row['assigned_ticket'])) {
        $conflicts[] = ['ticket' => $row['ticket_number'], 'reason' => 'assigned to a buyer'];
    } elseif ((int) $row['assigned_chapter_id'] > 0) {
        $conflicts[] = ['ticket' => $row['ticket_number'], 'reason' => 'allocated to a chapter'];
    } elseif ($row['ticket_status'] !== 'pending') {
        $conflicts[] = ['ticket' => $row['ticket_number'], 'reason' => 'status is ' . $row['ticket_status']];
    }
}
$stmt->close();

$missing = array_values(array_diff($ticketNumbers, $found));
foreach ($missing as $missingTicket) {
    $conflicts[] = ['ticket' => $missingTicket, 'reason' => 'not found in database'];
}

if (count($conflicts) > 0) {
    $sample = array_slice($conflicts, 0, 3);
    $sampleText = implode(', ', array_map(static fn($c) => $c['ticket'] . ' (' . $c['reason'] . ')', $sample));
    $more = count($conflicts) > 3 ? ' and ' . (count($conflicts) - 3) . ' more.' : '.';
    ctr_json(false, 'Conflicts found: ' . $sampleText . $more, $conflicts);
}

ctr_json(true, 'All ' . $rangeSize . ' ticket(s) in range are available.', []);
