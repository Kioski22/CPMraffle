<?php
require_once __DIR__ . '/db.php';
$stmt = $conn->prepare("
    UPDATE buyers b
    INNER JOIN ticket_assignments ta ON ta.buyer_id = b.id
    INNER JOIN tickets t ON t.ticket_number = ta.ticket_number
    INNER JOIN users u ON u.id = t.assigned_chapter_id
    SET b.remarks = u.remarks, b.updated_at = NOW()
    WHERE LOWER(u.role) = 'chapter' AND u.remarks IS NOT NULL AND u.remarks != ''
");
$stmt->execute();
echo "Affected rows: " . $stmt->affected_rows;
?>