<?php

if (!function_exists('ticket_email_template_table_map')) {
    function ticket_email_template_table_map(): array
    {
        return [
            'assignment' => [
                'title' => 'Assignment / Activation Delivery',
                'table' => 'email_template_assignment',
                'prefix' => 'assignment',
            ],
            'chapter_invitation' => [
                'title' => 'Chapter Portal Invitation',
                'table' => 'email_template_chapter_invitation',
                'prefix' => 'chapter_invitation',
            ],
            'activation_thankyou' => [
                'title' => 'Activation Thank You',
                'table' => 'email_template_activation_thankyou',
                'prefix' => 'activation_thankyou',
            ],
            'winner_contact' => [
                'title' => 'Winner Contact',
                'table' => 'email_template_winner_contact',
                'prefix' => 'winner_contact',
            ],
        ];
    }
}

if (!function_exists('ticket_email_template_defaults_from_config')) {
    function ticket_email_template_defaults_from_config(array $appConfig, string $templateKey): array
    {
        $map = ticket_email_template_table_map();
        if (!isset($map[$templateKey])) {
            throw new RuntimeException('Unknown email template type.');
        }

        $prefix = $map[$templateKey]['prefix'];
        $templateConfig = $appConfig['email_template'] ?? [];

        return [
            'format' => strtolower(trim((string) ($templateConfig[$prefix . '_format'] ?? 'html'))),
            'subject' => (string) ($templateConfig[$prefix . '_subject'] ?? ''),
            'body_html' => (string) ($templateConfig[$prefix . '_body_html'] ?? ''),
            'body_text' => (string) ($templateConfig[$prefix . '_body_text'] ?? ''),
        ];
    }
}

if (!function_exists('ensureEmailTemplateTables')) {
    function ensureEmailTemplateTables(mysqli $conn, array $appConfig): void
    {
        foreach (ticket_email_template_table_map() as $templateKey => $templateDefinition) {
            $tableName = $templateDefinition['table'];
            $conn->query("CREATE TABLE IF NOT EXISTS {$tableName} (
                id INT(11) NOT NULL PRIMARY KEY,
                format VARCHAR(10) NOT NULL DEFAULT 'html',
                subject TEXT NOT NULL,
                body_html MEDIUMTEXT NOT NULL,
                body_text MEDIUMTEXT NOT NULL,
                attach_ticket_layout TINYINT(1) NOT NULL DEFAULT 0,
                attachment_path VARCHAR(255) NULL,
                attachment_filename VARCHAR(255) NULL,
                updated_by_admin_id INT(11) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Runtime migration for existing tables that pre-date this column.
            $colCheck = $conn->query("SHOW COLUMNS FROM {$tableName} LIKE 'attach_ticket_layout'");
            if ($colCheck instanceof mysqli_result && $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE {$tableName} ADD COLUMN attach_ticket_layout TINYINT(1) NOT NULL DEFAULT 0");
            }
            if ($colCheck instanceof mysqli_result) {
                $colCheck->close();
            }

            $attachmentPathCheck = $conn->query("SHOW COLUMNS FROM {$tableName} LIKE 'attachment_path'");
            if ($attachmentPathCheck instanceof mysqli_result && $attachmentPathCheck->num_rows === 0) {
                $conn->query("ALTER TABLE {$tableName} ADD COLUMN attachment_path VARCHAR(255) NULL");
            }
            if ($attachmentPathCheck instanceof mysqli_result) {
                $attachmentPathCheck->close();
            }

            $attachmentFilenameCheck = $conn->query("SHOW COLUMNS FROM {$tableName} LIKE 'attachment_filename'");
            if ($attachmentFilenameCheck instanceof mysqli_result && $attachmentFilenameCheck->num_rows === 0) {
                $conn->query("ALTER TABLE {$tableName} ADD COLUMN attachment_filename VARCHAR(255) NULL");
            }
            if ($attachmentFilenameCheck instanceof mysqli_result) {
                $attachmentFilenameCheck->close();
            }

            $existingRow = $conn->query("SELECT id FROM {$tableName} WHERE id = 1 LIMIT 1");
            $hasSeed = $existingRow instanceof mysqli_result && $existingRow->num_rows > 0;
            if ($existingRow instanceof mysqli_result) {
                $existingRow->close();
            }
            if ($hasSeed) {
                continue;
            }

            $defaults = ticket_email_template_defaults_from_config($appConfig, $templateKey);
            $seedStmt = $conn->prepare("INSERT INTO {$tableName} (id, format, subject, body_html, body_text, attach_ticket_layout, attachment_path, attachment_filename, updated_by_admin_id)
                VALUES (1, ?, ?, ?, ?, 0, NULL, NULL, NULL)");
            if ($seedStmt) {
                $seedStmt->bind_param('ssss', $defaults['format'], $defaults['subject'], $defaults['body_html'], $defaults['body_text']);
                $seedStmt->execute();
                $seedStmt->close();
            }
        }
    }
}

if (!function_exists('ticket_fetch_email_template_record')) {
    function ticket_fetch_email_template_record(mysqli $conn, array $appConfig, string $templateKey): array
    {
        ensureEmailTemplateTables($conn, $appConfig);

        $map = ticket_email_template_table_map();
        if (!isset($map[$templateKey])) {
            throw new RuntimeException('Unknown email template type.');
        }

        $tableName = $map[$templateKey]['table'];
        $defaults = ticket_email_template_defaults_from_config($appConfig, $templateKey);
        $result = $conn->query("SELECT format, subject, body_html, body_text, attach_ticket_layout, attachment_path, attachment_filename, updated_by_admin_id, updated_at FROM {$tableName} WHERE id = 1 LIMIT 1");
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->close();
        }

        if (!is_array($row)) {
            return array_merge($defaults, [
                'attach_ticket_layout' => 0,
                'attachment_path' => null,
                'attachment_filename' => null,
                'updated_by_admin_id' => null,
                'updated_at' => null,
            ]);
        }

        return [
            'format' => in_array(strtolower((string) ($row['format'] ?? 'html')), ['html', 'text'], true) ? strtolower((string) $row['format']) : $defaults['format'],
            'subject' => (string) ($row['subject'] ?? $defaults['subject']),
            'body_html' => (string) ($row['body_html'] ?? $defaults['body_html']),
            'body_text' => (string) ($row['body_text'] ?? $defaults['body_text']),
            'attach_ticket_layout' => (int) ($row['attach_ticket_layout'] ?? 0),
            'attachment_path' => isset($row['attachment_path']) ? (string) $row['attachment_path'] : null,
            'attachment_filename' => isset($row['attachment_filename']) ? (string) $row['attachment_filename'] : null,
            'updated_by_admin_id' => $row['updated_by_admin_id'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('ticket_save_email_template_record')) {
    function ticket_save_email_template_record(mysqli $conn, array $appConfig, string $templateKey, array $payload, ?int $adminId = null): void
    {
        ensureEmailTemplateTables($conn, $appConfig);

        $map = ticket_email_template_table_map();
        if (!isset($map[$templateKey])) {
            throw new RuntimeException('Unknown email template type.');
        }

        $format = strtolower(trim((string) ($payload['format'] ?? 'html')));
        if (!in_array($format, ['html', 'text'], true)) {
            $format = 'html';
        }
        $subject = trim((string) ($payload['subject'] ?? ''));
        $bodyHtml = (string) ($payload['body_html'] ?? '');
        $bodyText = (string) ($payload['body_text'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('Subject is required.');
        }

        $attachTicketLayout = ((int) ($payload['attach_ticket_layout'] ?? 0) === 1) ? 1 : 0;
        $attachmentPath = isset($payload['attachment_path']) ? trim((string) $payload['attachment_path']) : '';
        $attachmentFilename = isset($payload['attachment_filename']) ? trim((string) $payload['attachment_filename']) : '';

        if ($attachmentPath === '' || $attachmentFilename === '') {
            $attachmentPath = null;
            $attachmentFilename = null;
        }

        $tableName = $map[$templateKey]['table'];
        $stmt = $conn->prepare("UPDATE {$tableName}
            SET format = ?, subject = ?, body_html = ?, body_text = ?,
                attach_ticket_layout = ?, attachment_path = ?, attachment_filename = ?, updated_by_admin_id = ?, updated_at = NOW()
            WHERE id = 1");
        if (!$stmt) {
            throw new RuntimeException('Unable to save email template.');
        }

        $stmt->bind_param('ssssissi', $format, $subject, $bodyHtml, $bodyText, $attachTicketLayout, $attachmentPath, $attachmentFilename, $adminId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ticket_load_db_email_templates')) {
    function ticket_load_db_email_templates(mysqli $conn, array $appConfig): array
    {
        ensureEmailTemplateTables($conn, $appConfig);
        $loaded = [];
        foreach (ticket_email_template_table_map() as $templateKey => $templateDefinition) {
            $record = ticket_fetch_email_template_record($conn, $appConfig, $templateKey);
            $prefix = $templateDefinition['prefix'];
            $loaded[$prefix . '_format'] = $record['format'];
            $loaded[$prefix . '_subject'] = $record['subject'];
            $loaded[$prefix . '_body_html'] = $record['body_html'];
            $loaded[$prefix . '_body_text'] = $record['body_text'];
            $loaded[$prefix . '_attachment_path'] = $record['attachment_path'];
            $loaded[$prefix . '_attachment_filename'] = $record['attachment_filename'];
        }

        return $loaded;
    }
}