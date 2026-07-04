<?php
$appConfig = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../email_composer.php';

$templateData = ticket_build_chapter_invitation_template_data([
    'chapter_name' => 'Metro Manila',
    'president_email' => 'president.metro@example.com',
    'username' => 'president.metro@example.com',
    'password' => 'TempPass!2026',
    'ticket_range_summary' => 'PSMECPMXWEN_00001 to PSMECPMXWEN_00050',
    'ticket_count' => '50',
    'current_date' => date('Y-m-d H:i:s'),
    'app_name' => (string) ($appConfig['mail']['from_name'] ?? 'Ticketing Team'),
]);

$email = ticket_compose_chapter_invitation_email($appConfig, $templateData);

if (strtolower((string) ($email['format'] ?? 'html')) !== 'html') {
    $body = nl2br(htmlspecialchars((string) ($email['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $subject = htmlspecialchars((string) ($email['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
    $wrapped = "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$subject}</title></head><body><h2>{$subject}</h2><pre>{$body}</pre></body></html>";
} else {
    $subject = htmlspecialchars((string) ($email['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
    $wrapped = "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$subject}</title></head><body style=\"margin:0;background:#eef2f7;padding:20px;\">" . (string) ($email['body'] ?? '') . '</body></html>';
}

file_put_contents(__DIR__ . '/../sample_chapter_email.html', $wrapped);
echo "Generated sample_chapter_email.html\n";
