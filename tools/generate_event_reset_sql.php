<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$outPath = __DIR__ . '/../rafflev2_event_reset.sql';
$preserveDataTables = ['users', 'system_settings'];
$resetDataTables = [
    'tickets',
    'generated_tickets',
    'buyers',
    'ticket_assignments',
    'ticket_activations',
    'ticket_purchase_requests',
    'chapter_allocations',
];

function sql_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . $conn->real_escape_string((string) $value) . "'";
}

$dbResult = $conn->query('SELECT DATABASE() AS db_name');
$dbName = 'rafflev2';
if ($dbResult instanceof mysqli_result) {
    $row = $dbResult->fetch_assoc();
    $resolved = trim((string) ($row['db_name'] ?? ''));
    if ($resolved !== '') {
        $dbName = $resolved;
    }
    $dbResult->close();
}

$tables = [];
$tableResult = $conn->query('SHOW TABLES');
if (!$tableResult instanceof mysqli_result) {
    throw new RuntimeException('Unable to enumerate tables for export.');
}
while ($row = $tableResult->fetch_row()) {
    $tables[] = (string) $row[0];
}
$tableResult->close();
sort($tables);

$lines = [];
$lines[] = '-- Generated on ' . date('Y-m-d H:i:s');
$lines[] = '-- CPM raffle event reset import for XAMPP';
$lines[] = '-- Preserved data tables: users, system_settings';
$lines[] = '-- Reset data tables: tickets, generated_tickets, buyers, ticket_assignments, ticket_activations, ticket_purchase_requests, chapter_allocations';
$lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
$lines[] = 'SET time_zone = "+00:00";';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$lines[] = '';
$lines[] = 'CREATE DATABASE IF NOT EXISTS ' . sql_identifier($dbName) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;';
$lines[] = 'USE ' . sql_identifier($dbName) . ';';
$lines[] = '';

foreach ($tables as $table) {
    $createResult = $conn->query('SHOW CREATE TABLE ' . sql_identifier($table));
    if (!$createResult instanceof mysqli_result) {
        continue;
    }

    $createRow = $createResult->fetch_assoc();
    $createResult->close();
    if (!is_array($createRow)) {
        continue;
    }

    $createSql = (string) ($createRow['Create Table'] ?? '');
    if ($createSql === '') {
        continue;
    }

    $lines[] = '--';
    $lines[] = '-- Table structure for table ' . sql_identifier($table);
    $lines[] = '--';
    $lines[] = 'DROP TABLE IF EXISTS ' . sql_identifier($table) . ';';
    $lines[] = $createSql . ';';
    $lines[] = '';
}

foreach ($preserveDataTables as $table) {
    if (!in_array($table, $tables, true)) {
        continue;
    }

    $dataResult = $conn->query('SELECT * FROM ' . sql_identifier($table));
    if (!$dataResult instanceof mysqli_result) {
        continue;
    }

    $fieldNames = [];
    foreach ($dataResult->fetch_fields() as $field) {
        $fieldNames[] = sql_identifier((string) $field->name);
    }

    if ($fieldNames === []) {
        $dataResult->close();
        continue;
    }

    $lines[] = '--';
    $lines[] = '-- Preserved data for table ' . sql_identifier($table);
    $lines[] = '--';

    while ($row = $dataResult->fetch_assoc()) {
        if (!is_array($row)) {
            continue;
        }

        $values = [];
        foreach ($row as $value) {
            $values[] = sql_value($conn, $value);
        }

        $lines[] = 'INSERT INTO ' . sql_identifier($table)
            . ' (' . implode(', ', $fieldNames) . ') VALUES ('
            . implode(', ', $values) . ');';
    }

    $dataResult->close();
    $lines[] = '';
}

$lines[] = '--';
$lines[] = '-- Ensure transactional tables are empty for new event';
$lines[] = '--';
foreach ($resetDataTables as $table) {
    if (!in_array($table, $tables, true)) {
        continue;
    }
    $lines[] = 'TRUNCATE TABLE ' . sql_identifier($table) . ';';
}
$lines[] = '';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';

file_put_contents($outPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo 'Generated: ' . $outPath . PHP_EOL;
echo 'Tables exported: ' . count($tables) . PHP_EOL;
