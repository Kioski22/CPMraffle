<?php
require __DIR__ . '/../db.php';
$result = $conn->query('SELECT COUNT(*) AS c FROM users');
$row = $result ? $result->fetch_assoc() : ['c' => 'ERR'];
echo 'DB_USERS=' . (string) ($row['c'] ?? 'ERR') . PHP_EOL;
