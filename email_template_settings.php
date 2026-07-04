<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);

include 'db.php';
require_once __DIR__ . '/email_template_store.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/ticket_mailer.php';

if (!function_exists('ticket_email_template_test_data')) {
    function ticket_email_template_test_data(string $templateKey, array $appConfig, string $testEmail): array
    {
        $mailConfig = $appConfig['mail'] ?? [];
        $now = date('Y-m-d H:i:s');

        if ($templateKey === 'assignment') {
            $activationLink = (string) (($appConfig['activation_portal']['base_url'] ?? '') ?: (($appConfig['app']['base_url'] ?? 'https://icto2.psmeinc.org.ph/CPMraffle') . '/verification.php'));

            return ticket_build_assignment_template_data([
                'full_name' => 'Test Recipient',
                'email' => $testEmail,
                'chapter' => 'Metro Manila',
                'buyer_type' => 'Individual',
                'ticket_list' => 'PSMECPMXWEN_00001, PSMECPMXWEN_00010',
                'ticket_ranges' => 'PSMECPMXWEN_00001 to PSMECPMXWEN_00010',
                'activation_link' => $activationLink,
                'activation_qr_cid' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($activationLink),
                'portal_expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'header_logo_left_url' => '',
                'header_logo_right_url' => '',
                'app_name' => (string) ($mailConfig['from_name'] ?? 'Ticketing Team'),
            ]);
        }

        if ($templateKey === 'chapter_invitation') {
            return ticket_build_chapter_invitation_template_data([
                'chapter_name' => 'Metro Manila',
                'president_email' => $testEmail,
                'username' => 'chapter.metro@example.com',
                'password' => 'TestPass123!',
                'portal_link' => (string) (($appConfig['app']['base_url'] ?? 'https://icto2.psmeinc.org.ph/CPMraffle') . '/chapter_login.php'),
                'ticket_range_summary' => 'PSMECPMXWEN_00001 to PSMECPMXWEN_00050',
                'ticket_count' => '50',
                'app_name' => (string) ($mailConfig['from_name'] ?? 'Ticketing Team'),
            ]);
        }

        if ($templateKey === 'activation_thankyou') {
            return ticket_build_activation_template_data([
                'full_name' => 'Test Recipient',
                'email' => $testEmail,
                'contact_number' => '+639171234567',
                'activated_ticket_list' => 'PSMECPMXWEN_00001, PSMECPMXWEN_00010',
                'activation_date' => $now,
                'app_name' => (string) ($mailConfig['from_name'] ?? 'Ticketing Team'),
            ]);
        }

        return [
            'full_name' => 'Test Recipient',
            'email' => $testEmail,
            'prize_name' => 'Grand Prize',
            'claim_deadline' => date('Y-m-d', strtotime('+14 days')),
            'claim_contact' => (string) ($mailConfig['from_address'] ?? 'noreply@example.com'),
            'app_name' => (string) ($mailConfig['from_name'] ?? 'Ticketing Team'),
            'current_date' => $now,
        ];
    }
}

if (!function_exists('ticket_compose_email_from_payload')) {
    function ticket_compose_email_from_payload(array $payload, array $templateData): array
    {
        $format = strtolower(trim((string) ($payload['format'] ?? 'html')));
        if (!in_array($format, ['html', 'text'], true)) {
            $format = 'html';
        }

        $subjectTemplate = (string) ($payload['subject'] ?? 'Test Email');
        $htmlTemplate = ticket_template_decode_multiline((string) ($payload['body_html'] ?? ''));
        $textTemplate = ticket_template_decode_multiline((string) ($payload['body_text'] ?? ''));

        $rawVars = [];
        $htmlVars = [];
        foreach ($templateData as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $stringValue = (string) $value;
            $rawVars[$normalizedKey] = $stringValue;
            $htmlVars[$normalizedKey] = htmlspecialchars($stringValue, ENT_QUOTES, 'UTF-8');
        }
        if (isset($rawVars['ticket_image'])) {
            $htmlVars['ticket_image'] = $rawVars['ticket_image'];
        }

        $subject = ticket_render_placeholders($subjectTemplate, $rawVars);
        if ($format === 'text') {
            $body = ticket_render_placeholders($textTemplate, $rawVars);
            return [
                'subject' => $subject,
                'body' => $body,
                'format' => 'text',
                'content_type' => 'text/plain; charset=UTF-8',
            ];
        }

        $body = ticket_render_placeholders($htmlTemplate, $htmlVars);
        return [
            'subject' => $subject,
            'body' => $body,
            'format' => 'html',
            'content_type' => 'text/html; charset=UTF-8',
        ];
    }
}

if (!function_exists('ticket_email_template_placeholders_map')) {
    function ticket_email_template_placeholders_map(): array
    {
        return [
            'assignment' => [
                ['key' => 'full_name', 'label' => 'Recipient\'s full name'],
                ['key' => 'email', 'label' => 'Recipient\'s email address'],
                ['key' => 'chapter', 'label' => 'Assigned chapter'],
                ['key' => 'buyer_type', 'label' => 'Buyer type (for example Individual or Chapter)'],
                ['key' => 'ticket_list', 'label' => 'Ticket numbers as a comma-separated list'],
                ['key' => 'ticket_ranges', 'label' => 'Summarized ticket range'],
                ['key' => 'ticket_count', 'label' => 'Total number of assigned tickets'],
                ['key' => 'first_ticket', 'label' => 'First ticket number in the list'],
                ['key' => 'last_ticket', 'label' => 'Last ticket number in the list'],
                ['key' => 'activation_link', 'label' => 'Ticket activation URL'],
                ['key' => 'activation_qr_cid', 'label' => 'Inline QR code CID reference'],
                ['key' => 'portal_expires_at', 'label' => 'Activation portal expiration date and time'],
                ['key' => 'header_logo_left_url', 'label' => 'Left header logo source'],
                ['key' => 'header_logo_right_url', 'label' => 'Right header logo source'],
                ['key' => 'current_date', 'label' => 'Current date and time'],
                ['key' => 'app_name', 'label' => 'Application name'],
                ['key' => 'ticket_image', 'label' => 'Embedded ticket layout image (HTML templates only)'],
            ],
            'chapter_invitation' => [
                ['key' => 'chapter_name', 'label' => 'Chapter name'],
                ['key' => 'president_email', 'label' => 'Chapter president email'],
                ['key' => 'username', 'label' => 'Portal username'],
                ['key' => 'password', 'label' => 'Temporary portal password'],
                ['key' => 'portal_link', 'label' => 'Portal login URL'],
                ['key' => 'ticket_range_summary', 'label' => 'Allocated ticket range summary'],
                ['key' => 'ticket_count', 'label' => 'Total allocated tickets'],
                ['key' => 'current_date', 'label' => 'Current date and time'],
                ['key' => 'app_name', 'label' => 'Application name'],
            ],
            'activation_thankyou' => [
                ['key' => 'full_name', 'label' => 'Recipient\'s full name'],
                ['key' => 'email', 'label' => 'Recipient\'s email address'],
                ['key' => 'contact_number', 'label' => 'Contact number'],
                ['key' => 'activated_ticket_list', 'label' => 'Activated ticket list'],
                ['key' => 'activated_ticket_count', 'label' => 'Number of activated tickets'],
                ['key' => 'activation_date', 'label' => 'Activation date and time'],
                ['key' => 'app_name', 'label' => 'Application name'],
                ['key' => 'prize_name', 'label' => 'Prize name'],
                ['key' => 'claim_deadline', 'label' => 'Prize claim deadline'],
                ['key' => 'claim_contact', 'label' => 'Prize claim contact information'],
            ],
            'winner_contact' => [
                ['key' => 'full_name', 'label' => 'Winner\'s full name'],
                ['key' => 'email', 'label' => 'Winner\'s email address'],
                ['key' => 'prize_name', 'label' => 'Prize name'],
                ['key' => 'claim_deadline', 'label' => 'Prize claim deadline'],
                ['key' => 'claim_contact', 'label' => 'Prize claim contact information'],
                ['key' => 'current_date', 'label' => 'Current date and time'],
                ['key' => 'app_name', 'label' => 'Application name'],
            ],
        ];
    }
}

if (!function_exists('ticket_email_template_attachment_allowed_map')) {
    function ticket_email_template_attachment_allowed_map(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain', 'application/octet-stream'],
        ];
    }
}

if (!function_exists('ticket_email_template_attachment_storage_relative_dir')) {
    function ticket_email_template_attachment_storage_relative_dir(): string
    {
        return 'uploads/attachments';
    }
}

if (!function_exists('ticket_email_template_attachment_storage_absolute_dir')) {
    function ticket_email_template_attachment_storage_absolute_dir(): string
    {
        return __DIR__ . '/' . ticket_email_template_attachment_storage_relative_dir();
    }
}

if (!function_exists('ticket_email_template_remove_managed_attachment')) {
    function ticket_email_template_remove_managed_attachment(string $storedPath): void
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return;
        }

        $absolutePath = __DIR__ . '/' . ltrim($storedPath, '/\\');
        $storageRoot = realpath(ticket_email_template_attachment_storage_absolute_dir());
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

if (!function_exists('ticket_email_template_process_attachment')) {
    function ticket_email_template_process_attachment(string $templateKey, array $currentTemplate, array $post, array $files): array
    {
        $result = [
            'attachment_path' => isset($currentTemplate['attachment_path']) ? trim((string) $currentTemplate['attachment_path']) : '',
            'attachment_filename' => isset($currentTemplate['attachment_filename']) ? trim((string) $currentTemplate['attachment_filename']) : '',
        ];

        $removeRequested = isset($post['remove_attachment']) && (string) $post['remove_attachment'] === '1';
        if ($removeRequested && $result['attachment_path'] !== '') {
            ticket_email_template_remove_managed_attachment($result['attachment_path']);
            $result['attachment_path'] = '';
            $result['attachment_filename'] = '';
        }

        if (!isset($files['template_attachment']) || !is_array($files['template_attachment'])) {
            return $result;
        }

        $upload = $files['template_attachment'];
        $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return $result;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Attachment upload failed. Please try again.');
        }

        $tmpName = (string) ($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded attachment is invalid.');
        }

        $originalName = trim((string) ($upload['name'] ?? 'attachment'));
        $baseOriginalName = basename($originalName);
        $extension = strtolower(pathinfo($baseOriginalName, PATHINFO_EXTENSION));
        $allowedMap = ticket_email_template_attachment_allowed_map();
        if ($extension === '' || !isset($allowedMap[$extension])) {
            throw new RuntimeException('Unsupported attachment type. Allowed: ' . implode(', ', array_keys($allowedMap)) . '.');
        }

        $finfoMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $finfoMime = (string) finfo_file($finfo, $tmpName);
                finfo_close($finfo);
            }
        }
        $allowedMimes = (array) ($allowedMap[$extension] ?? []);
        if ($finfoMime !== '' && $allowedMimes !== [] && !in_array($finfoMime, $allowedMimes, true)) {
            throw new RuntimeException('Attachment MIME type mismatch for .' . $extension . '.');
        }

        $storageAbsoluteDir = ticket_email_template_attachment_storage_absolute_dir();
        if (!is_dir($storageAbsoluteDir) && !mkdir($storageAbsoluteDir, 0775, true) && !is_dir($storageAbsoluteDir)) {
            throw new RuntimeException('Unable to create attachment storage directory.');
        }

        $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseOriginalName);
        if ($safeOriginalName === null || $safeOriginalName === '' || $safeOriginalName === '.' || $safeOriginalName === '..') {
            $safeOriginalName = 'attachment.' . $extension;
        }

        $storedFilename = $templateKey . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $targetAbsolutePath = $storageAbsoluteDir . '/' . $storedFilename;
        if (!move_uploaded_file($tmpName, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to save uploaded attachment.');
        }

        if ($result['attachment_path'] !== '') {
            ticket_email_template_remove_managed_attachment($result['attachment_path']);
        }

        $result['attachment_path'] = ticket_email_template_attachment_storage_relative_dir() . '/' . $storedFilename;
        $result['attachment_filename'] = $safeOriginalName;

        return $result;
    }
}

ensureEmailTemplateTables($conn, $appConfig);

$templateDefinitions = ticket_email_template_table_map();
$selectedTemplateKey = trim((string) ($_GET['template_type'] ?? $_POST['template_type'] ?? 'assignment'));
if (!isset($templateDefinitions[$selectedTemplateKey])) {
    $selectedTemplateKey = 'assignment';
}

$flashMessage = '';
$flashType = 'success';
$testEmail = trim((string) ($_POST['test_email'] ?? ''));
$selectedTemplate = ticket_fetch_email_template_record($conn, $appConfig, $selectedTemplateKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $adminId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
        $attachmentData = ticket_email_template_process_attachment($selectedTemplateKey, $selectedTemplate, $_POST, $_FILES);
        $payload = [
            'format' => (string) ($_POST['format'] ?? 'html'),
            'subject' => (string) ($_POST['subject'] ?? ''),
            'body_html' => (string) ($_POST['body_html'] ?? ''),
            'body_text' => (string) ($_POST['body_text'] ?? ''),
            'attach_ticket_layout' => isset($_POST['attach_ticket_layout']) ? 1 : 0,
            'attachment_path' => $attachmentData['attachment_path'],
            'attachment_filename' => $attachmentData['attachment_filename'],
        ];

        $formAction = trim((string) ($_POST['form_action'] ?? 'save'));
        ticket_save_email_template_record($conn, $appConfig, $selectedTemplateKey, $payload, $adminId);

        if ($formAction === 'send_test') {
            if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid recipient email address for the test send.');
            }

            $templateData = ticket_email_template_test_data($selectedTemplateKey, $appConfig, $testEmail);
            $inlineImages = [];

            if ($selectedTemplateKey === 'assignment') {
                $templateData['header_logo_left_url'] = '';
                $templateData['header_logo_right_url'] = '';
            }

            if ($selectedTemplateKey === 'assignment'
                && ((int) ($payload['attach_ticket_layout'] ?? 0) === 1)
                && strtolower((string) ($payload['format'] ?? 'html')) === 'html') {
                require_once __DIR__ . '/ticket_layout_helper.php';
                require_once __DIR__ . '/ticket_image_generator.php';

                $layout = ticket_layout_load();
                $sampleTickets = ['PSMECPMXWEN_00001', 'PSMECPMXWEN_00010'];
                $sampleActivationLink = trim((string) ($templateData['activation_link'] ?? ''));
                if ($sampleActivationLink === '') {
                    $sampleActivationLink = (string) (($appConfig['activation_portal']['base_url'] ?? '') ?: (($appConfig['app']['base_url'] ?? 'https://icto2.psmeinc.org.ph/CPMraffle') . '/verification.php'));
                }
                $ticketImages = ticket_generate_buyer_email_images($layout, $sampleTickets, $sampleActivationLink);
                if ($ticketImages !== []) {
                    $imgHtml = '<div style="margin:0 0 14px;">';
                    $imgHtml .= '<p style="font-family:sans-serif;font-size:14px;font-weight:600;color:#374151;margin:0 0 8px;">Your Raffle Ticket(s):</p>';
                    foreach ($ticketImages as $img) {
                        $safeCid = htmlspecialchars((string) ($img['cid'] ?? ''), ENT_QUOTES);
                        $safeTn = htmlspecialchars((string) ($img['ticket_number'] ?? ''), ENT_QUOTES);
                        $imgHtml .= '<img src="cid:' . $safeCid . '" alt="Raffle Ticket ' . $safeTn . '" style="display:block;max-width:100%;height:auto;border:1px solid #d1d5db;border-radius:8px;margin:8px 0;">';
                    }
                    $imgHtml .= '</div>';
                    $templateData['ticket_image'] = $imgHtml;
                    $inlineImages = array_merge($inlineImages, $ticketImages);
                }
            }

            $emailParts = ticket_compose_email_from_payload($payload, $templateData);
            if (strtolower((string) ($emailParts['format'] ?? 'html')) === 'html' && $inlineImages !== []) {
                $emailParts['inline_images'] = $inlineImages;
            }

            $attachments = ticket_email_collect_attachment_parts($payload);
            if ($attachments !== []) {
                $emailParts['attachments'] = $attachments;
            }

            $deliveryMethod = ticket_send_gmail_message($appConfig, 'Test Recipient', $testEmail, $emailParts);
            $flashMessage = 'Template saved. Test email sent to ' . $testEmail . ' via ' . $deliveryMethod . '.';
            $flashType = 'success';
        } else {
            $flashMessage = $templateDefinitions[$selectedTemplateKey]['title'] . ' template saved to table `' . $templateDefinitions[$selectedTemplateKey]['table'] . '`.';
            $flashType = 'success';
        }
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$selectedTemplate = ticket_fetch_email_template_record($conn, $appConfig, $selectedTemplateKey);
$placeholderMap = ticket_email_template_placeholders_map();
$availablePlaceholders = $placeholderMap[$selectedTemplateKey] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Template Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }
        :root {
            --primary: #283555;
            --accent: #ff6b35;
            --bg: #f9fafc;
            --text-dark: #1a202c;
            --text-light: #64748b;
            --border: #e2e8f0;
            --white: #ffffff;
        }
        body {
            background: var(--bg);
            min-height: 100vh;
            color: var(--text-dark);
        }
        .container {
            max-width: 900px;
        }
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        aside.sidebar {
            width: 250px;
            height: 100vh;
            top: 0;
            z-index: 50;
            background: var(--primary);
            color: white;
            padding: 22px 20px 30px 20px;
            position: fixed;
            bottom: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            box-shadow: 2px 0 4px rgba(0, 0, 0, 0.1);
        }
        aside.sidebar .sidebar-header {
            text-align: center;
            align-items: center;
            margin-bottom: 10px;
            display: flex;
        }
        aside.sidebar .sidebar-header h1 {
            font-size: 16px;
            color: var(--white);
            text-transform: uppercase;
            margin: 0;
        }
        aside.sidebar nav ul {
            list-style: none;
            padding: 0;
            margin-top: 10px;
        }
        aside.sidebar nav ul li {
            margin: 10px 0;
        }
        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s ease;
            padding: 8px 12px;
            border-radius: 5px;
        }
        aside.sidebar nav ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background: rgba(255, 107, 53, 0.15);
            color: var(--white);
            padding-left: 15px;
        }
        main.main-content {
            flex: 1;
            padding: 3rem 2rem;
            margin-left: 280px;
        }
        .header-section {
            margin-bottom: 2.5rem;
        }
        .header-section h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        .header-section p {
            color: var(--text-light);
            font-size: 0.95rem;
            margin: 0;
        }
        .card {
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .card-header {
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 600;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        .form-control, .form-select {
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.65rem 0.9rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40, 53, 85, 0.08);
        }
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .helper-text {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-top: 0.35rem;
        }
        .selector-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 1.5rem;
        }
        .editor-wrapper {
            display: grid;
            gap: 1.5rem;
        }
        .form-section {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.5rem;
        }
        .form-section h3 {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0 0 1rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .form-row:last-child {
            margin-bottom: 0;
        }
        textarea.form-control {
            min-height: 200px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.85rem;
            resize: vertical;
        }
        .button-group {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .btn {
            padding: 0.65rem 1.25rem;
            font-weight: 600;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: #1f2a3d;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 53, 85, 0.15);
        }
        .btn-outline-secondary {
            border: 1px solid var(--border);
            color: var(--text-dark);
            background: transparent;
        }
        .btn-outline-secondary:hover {
            background: var(--bg);
            border-color: var(--primary);
            color: var(--primary);
        }
        .placeholder-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .placeholder-modal.active {
            display: flex;
        }
        .placeholder-modal-content {
            background: var(--white);
            border-radius: 10px;
            padding: 2rem;
            max-width: 500px;
            max-height: 70vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .placeholder-modal h2 {
            font-size: 1.3rem;
            margin: 0 0 1.5rem;
            color: var(--text-dark);
        }
        .placeholder-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .placeholder-item {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.75rem;
            color: var(--text-dark);
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
        }
        .placeholder-item code {
            display: inline-block;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.82rem;
            color: #0f172a;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 4px;
            padding: 0.2rem 0.45rem;
            margin-bottom: 0.35rem;
        }
        .placeholder-item small {
            display: block;
            color: var(--text-light);
            font-size: 0.8rem;
            line-height: 1.35;
        }
        .placeholder-item:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .placeholder-item:hover code {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.35);
        }
        .placeholder-item:hover small {
            color: rgba(255, 255, 255, 0.9);
        }
        .attachment-meta {
            margin-top: 0.65rem;
            padding: 0.65rem 0.75rem;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            color: #334155;
            font-size: 0.86rem;
        }
        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
        }
        .modal-close:hover {
            color: var(--text-dark);
        }
        .alert {
            border: none;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
        }
        .alert-danger {
            background: #fef2f2;
            color: #7f1d1d;
        }
        @media (max-width: 768px) {
            aside.sidebar {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
            }
            main.main-content {
                margin-left: 0;
                padding: 1.5rem 1rem;
            }
            .selector-row {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .placeholder-list {
                grid-template-columns: 1fr;
            }
            .placeholder-modal-content {
                max-width: 90vw;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="/CPMraffle/admin_dashboard.php" style="text-decoration:none;">
                <h1>6th PSME CPM National Conference</h1>
            </a>
        </div>
        <hr>
        <nav>
            <ul>
                <li><a href="/CPMraffle/activated_tickets.php"><i class="fas fa-check-circle"></i> Activated Tickets</a></li>
                <li><a href="/CPMraffle/pending_tickets.php"><i class="fas fa-hourglass-half"></i> Pending Tickets</a></li>
                <li><a href="/CPMraffle/generate_qr.php"><i class="fas fa-ticket-alt"></i> Ticket Generation</a></li>
                <li><a href="/CPMraffle/pool_tickets.php"><i class="fas fa-layer-group"></i> Ticket Pool</a></li>
                <li><a href="/CPMraffle/buyer.php"><i class="fas fa-user-plus"></i> Buyer Assignment</a></li>
                <li><a href="/CPMraffle/bulk_buyer_upload_admin.php"><i class="fas fa-file-upload"></i> Bulk Buyer Upload</a></li>
                <li><a href="/CPMraffle/participant_list.php"><i class="fas fa-users"></i> Buyer List</a></li>
                <li><a href="/CPMraffle/ticket_layout_editor.php"><i class="fas fa-object-group"></i> Ticket Layout</a></li>
                <li><a class="active" href="/CPMraffle/email_template_settings.php"><i class="fas fa-envelope-open-text"></i> Email Templates</a></li>
                <li><a href="/CPMraffle/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="container">
            <div class="header-section">
                <h1>Email Templates</h1>
                <p>Customize email messages sent to buyers</p>
            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <div class="selector-row">
                <form method="GET" style="flex: 1; display: flex; gap: 0.75rem; align-items: flex-end;">
                    <div style="flex: 1;">
                        <label class="form-label">Email Type</label>
                        <select class="form-select" name="template_type" onchange="this.form.submit()">
                            <?php foreach ($templateDefinitions as $templateKey => $templateDefinition): ?>
                                <option value="<?php echo htmlspecialchars($templateKey, ENT_QUOTES); ?>" <?php echo $selectedTemplateKey === $templateKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($templateDefinition['title'], ENT_QUOTES); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-outline-secondary" onclick="openPlaceholderModal()">
                        <i class="fas fa-code"></i> Placeholders
                    </button>
                </form>
            </div>

            <form method="POST" class="editor-wrapper" enctype="multipart/form-data">
                <input type="hidden" name="template_type" value="<?php echo htmlspecialchars($selectedTemplateKey, ENT_QUOTES); ?>">

                <div class="form-section">
                    <h3>Email Format & Subject</h3>
                    <div class="form-row">
                        <div>
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format">
                                <option value="html" <?php echo ($selectedTemplate['format'] ?? 'html') === 'html' ? 'selected' : ''; ?>>HTML</option>
                                <option value="text" <?php echo ($selectedTemplate['format'] ?? 'html') === 'text' ? 'selected' : ''; ?>>Text Only</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control" name="subject" value="<?php echo htmlspecialchars((string) ($selectedTemplate['subject'] ?? ''), ENT_QUOTES); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>HTML Template</h3>
                    <p class="helper-text">Used when format is set to HTML</p>
                    <textarea class="form-control" name="body_html" placeholder="Enter HTML template..."><?php echo htmlspecialchars((string) ($selectedTemplate['body_html'] ?? ''), ENT_QUOTES); ?></textarea>
                </div>

                <div class="form-section">
                    <h3>Text Template</h3>
                    <p class="helper-text">Used when format is set to Text Only</p>
                    <textarea class="form-control" name="body_text" placeholder="Enter plain text template..."><?php echo htmlspecialchars((string) ($selectedTemplate['body_text'] ?? ''), ENT_QUOTES); ?></textarea>
                </div>

                <div class="form-section">
                    <h3>Template Attachment</h3>
                    <p class="helper-text">Upload one file to attach to every email sent with this template. Allowed file types: PDF, DOC, DOCX, PNG, JPG, JPEG, WEBP, TXT.</p>
                    <input type="file" class="form-control" name="template_attachment" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.txt">

                    <?php if (!empty($selectedTemplate['attachment_filename']) && !empty($selectedTemplate['attachment_path'])): ?>
                        <div class="attachment-meta">
                            Current attachment: <strong><?php echo htmlspecialchars((string) $selectedTemplate['attachment_filename'], ENT_QUOTES); ?></strong>
                        </div>
                        <div class="form-check" style="margin-top:0.75rem;">
                            <input class="form-check-input" type="checkbox" name="remove_attachment" id="remove_attachment" value="1">
                            <label class="form-check-label" for="remove_attachment">Remove current attachment when saving</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3>Ticket Layout Image</h3>
                    <p class="helper-text">When enabled and format is <strong>HTML</strong>, the buyer's raffle ticket is rendered using the <a href="/CPMraffle/ticket_layout_editor.php" target="_blank">Ticket Layout Editor</a> settings and embedded inline using the {{ticket_image}} placeholder. Only applies to buyer assignment emails.</p>
                    <div class="form-check form-switch" style="margin-top:0.75rem;">
                        <input class="form-check-input" type="checkbox" name="attach_ticket_layout" id="attach_ticket_layout" value="1"
                            <?php echo !empty($selectedTemplate['attach_ticket_layout']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="attach_ticket_layout">Embed ticket image in this email</label>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Send Test Email</h3>
                    <p class="helper-text">Sends the currently edited template to a test recipient. This action also saves your template changes first.</p>
                    <div class="form-row">
                        <div>
                            <label class="form-label" for="test_email">Recipient Email</label>
                        </div>
                        <div>
                            <input type="email" class="form-control" name="test_email" id="test_email" placeholder="name@example.com" value="<?php echo htmlspecialchars($testEmail, ENT_QUOTES); ?>">
                        </div>
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-outline-secondary" name="form_action" value="send_test">
                        <i class="fas fa-paper-plane"></i> Save & Send Test
                    </button>
                    <button type="submit" class="btn btn-primary" name="form_action" value="save">
                        <i class="fas fa-save"></i> Save Template
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<div class="placeholder-modal" id="placeholderModal">
    <div class="placeholder-modal-content" style="position: relative;">
        <button class="modal-close" onclick="closePlaceholderModal()">
            <i class="fas fa-times"></i>
        </button>
        <h2>Available Placeholders</h2>
        <p class="helper-text" style="margin-bottom: 1.5rem;">Showing placeholders for <strong><?php echo htmlspecialchars($templateDefinitions[$selectedTemplateKey]['title'] ?? 'this template', ENT_QUOTES); ?></strong>. Click to copy.</p>
        <div class="placeholder-list">
            <?php foreach ($availablePlaceholders as $placeholder): ?>
                <?php $placeholderToken = '{{' . (string) ($placeholder['key'] ?? '') . '}}'; ?>
                <div class="placeholder-item" onclick="copyToClipboard('<?php echo htmlspecialchars($placeholderToken, ENT_QUOTES); ?>', this)">
                    <code><?php echo htmlspecialchars($placeholderToken, ENT_QUOTES); ?></code>
                    <small><?php echo htmlspecialchars((string) ($placeholder['label'] ?? ''), ENT_QUOTES); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function openPlaceholderModal() {
    document.getElementById('placeholderModal').classList.add('active');
}

function closePlaceholderModal() {
    document.getElementById('placeholderModal').classList.remove('active');
}

function copyToClipboard(text, itemElement) {
    navigator.clipboard.writeText(text).then(() => {
        const btn = itemElement || null;
        if (!btn) {
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<code>' + text + '</code><small>Copied to clipboard.</small>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 1500);
    });
}

document.getElementById('placeholderModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePlaceholderModal();
    }
});
</script>
</body>
</html>

