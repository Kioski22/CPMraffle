<?php

include __DIR__ . '/../db.php';

$buyersAlter = "ALTER TABLE buyers ADD COLUMN remarks VARCHAR(255) DEFAULT NULL";
$usersAlter = "ALTER TABLE users ADD COLUMN remarks VARCHAR(255) DEFAULT NULL";

function runAlter(mysqli $conn, string $label, string $sql): void
{
    try {
        if ($conn->query($sql)) {
            echo $label . ": added" . PHP_EOL;
            return;
        }

        $error = strtolower((string) $conn->error);
        if (strpos($error, 'duplicate column') !== false) {
            echo $label . ": already exists" . PHP_EOL;
            return;
        }

        echo $label . ": failed - " . $conn->error . PHP_EOL;
    } catch (mysqli_sql_exception $exception) {
        $message = strtolower($exception->getMessage());
        if ((int) $exception->getCode() === 1060 || strpos($message, 'duplicate column') !== false) {
            echo $label . ": already exists" . PHP_EOL;
            return;
        }

        echo $label . ": failed - " . $exception->getMessage() . PHP_EOL;
    }
}

runAlter($conn, 'buyers.remarks', $buyersAlter);
runAlter($conn, 'users.remarks', $usersAlter);
