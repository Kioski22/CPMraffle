<?php

/**
 * Read a config value from environment first, then optional local secrets file.
 */
if (!function_exists('ticket_config_value')) {
    function ticket_config_value(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === null || trim((string) $value) === '') {
            $value = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
        }
        if ((($value === null || trim((string) $value) === '') && function_exists('apache_getenv'))) {
            $apacheValue = apache_getenv($key, true);
            if ($apacheValue !== false && trim((string) $apacheValue) !== '') {
                $value = $apacheValue;
            }
        }

        static $localSecrets = null;
        if ($localSecrets === null) {
            $localSecretsFile = __DIR__ . '/ticket_secrets.php';
            $localSecrets = is_file($localSecretsFile)
                ? (require $localSecretsFile)
                : [];
            if (!is_array($localSecrets)) {
                $localSecrets = [];
            }
        }

        if (($value === null || trim((string) $value) === '') && array_key_exists($key, $localSecrets)) {
            $value = $localSecrets[$key];
        }

        $resolved = trim((string) ($value ?? ''));
        if ($resolved === '') {
            $resolved = $default;
        }

        return $resolved;
    }
}

date_default_timezone_set(ticket_config_value('TICKET_APP_TIMEZONE', 'Asia/Manila'));

return [
    'db' => [
        // Support both project-prefixed vars and common hosting DB_* vars.
        'host' => ticket_config_value('TICKET_DB_HOST', ticket_config_value('DB_HOST', 'localhost')),
        'port' => (int) ticket_config_value('TICKET_DB_PORT', ticket_config_value('DB_PORT', '3306')),
        'socket' => ticket_config_value('TICKET_DB_SOCKET', ticket_config_value('DB_SOCKET', '')),
        'connect_timeout' => (int) ticket_config_value('TICKET_DB_CONNECT_TIMEOUT', ticket_config_value('DB_CONNECT_TIMEOUT', '5')),
        'username' => ticket_config_value('TICKET_DB_USERNAME', ticket_config_value('DB_USERNAME', ticket_config_value('DB_USER', 'root'))),
        'password' => ticket_config_value('TICKET_DB_PASSWORD', ticket_config_value('DB_PASSWORD', '')),
        'database' => ticket_config_value('TICKET_DB_NAME', ticket_config_value('DB_DATABASE', ticket_config_value('DB_NAME', 'rafflev2'))),
        'debug_errors' => ticket_config_value('TICKET_DB_DEBUG_ERRORS', ticket_config_value('DB_DEBUG_ERRORS', '0')) === '1',
    ],
    'app' => [
        'base_url' => ticket_config_value('TICKET_APP_BASE_URL', 'https://icto2.psmeinc.org.ph/CPMraffle'),
        'activation_cutoff' => ticket_config_value('TICKET_ACTIVATION_CUTOFF', '2026-09-05 15:00:00'),
        'raffle_mechanics_url' => ticket_config_value('TICKET_RAFFLE_MECHANICS_URL', ticket_config_value('TICKET_APP_BASE_URL', 'https://icto2.psmeinc.org.ph/CPMraffle') . '/instruction.php'),
        'header_logo_left_url' => ticket_config_value('TICKET_HEADER_LOGO_LEFT_URL', ticket_config_value('TICKET_APP_BASE_URL', 'https://icto2.psmeinc.org.ph/CPMraffle') . '/natcon.ico'),
        'header_logo_right_url' => ticket_config_value('TICKET_HEADER_LOGO_RIGHT_URL', ticket_config_value('TICKET_APP_BASE_URL', 'https://icto2.psmeinc.org.ph/CPMraffle') . '/psmefwd.ico'),
    ],
    'activation_portal' => [
        'base_url' => ticket_config_value('TICKET_ACTIVATION_PORTAL_URL', ticket_config_value('TICKET_APP_BASE_URL', 'https://icto2.psmeinc.org.ph/CPMraffle') . '/verification.php'),
        'token_ttl_seconds' => (int) ticket_config_value('TICKET_ACTIVATION_TOKEN_TTL', '604800'),
    ],
    'gmail_api' => [
        'credentials_path' => ticket_config_value('TICKET_GMAIL_CREDENTIALS_PATH', __DIR__ . '/jisonbago.json'),
        'refresh_token' => ticket_config_value('TICKET_GMAIL_REFRESH_TOKEN', ''),
        'user_id' => ticket_config_value('TICKET_GMAIL_USER_ID', 'me'),
    ],
    'google_drive' => [
        'credentials_path' => ticket_config_value('TICKET_DRIVE_CREDENTIALS_PATH', ticket_config_value('TICKET_GMAIL_CREDENTIALS_PATH', __DIR__ . '/jisonbago.json')),
        'refresh_token' => ticket_config_value('TICKET_DRIVE_REFRESH_TOKEN', ticket_config_value('TICKET_GMAIL_REFRESH_TOKEN', '')),
        'folder_id' => ticket_config_value('TICKET_DRIVE_FOLDER_ID', ''),
    ],
    'mail' => [
        'host' => ticket_config_value('TICKET_SMTP_HOST', 'smtp.gmail.com'),
        'port' => (int) ticket_config_value('TICKET_SMTP_PORT', '587'),
        'username' => ticket_config_value('TICKET_SMTP_USERNAME', 'noreplyraffle@psmeinc.org.ph'),
        'password' => ticket_config_value('TICKET_SMTP_PASSWORD', 'dpmo fvjo ezfp uweo'),
        'encryption' => ticket_config_value('TICKET_SMTP_ENCRYPTION', 'tls'),
        'from_address' => ticket_config_value('TICKET_MAIL_FROM', 'noreplyraffle@psmeinc.org.ph'),
        'from_name' => ticket_config_value('TICKET_MAIL_FROM_NAME', 'No-Reply Raffle'),
    ],
    'email_template' => [
        'assignment_format' => ticket_config_value('TICKET_ASSIGNMENT_EMAIL_FORMAT', 'html'),
        'assignment_subject' => ticket_config_value('TICKET_ASSIGNMENT_EMAIL_SUBJECT', 'Your Ticket Assignment - {{ticket_count}} Ticket(s)'),
        'assignment_body_html' => ticket_config_value(
            'TICKET_ASSIGNMENT_EMAIL_HTML',
            '<p>Hello {{full_name}},</p><p>Your ticket assignment is ready.</p><p><strong>Tickets:</strong> {{ticket_list}}</p><p><strong>Ranges:</strong> {{ticket_ranges}}</p><p><strong>Count:</strong> {{ticket_count}}</p><p><strong>Activation Link:</strong> <a href="{{activation_link}}">Activate your tickets</a></p><p>Or scan the QR code below:</p><p><img src="{{activation_qr_cid}}" alt="Activation QR Code" style="max-width:150px;height:auto;border:1px solid #d1d5db;border-radius:8px;padding:6px;background:#fff;"></p><p><strong>Portal Expires:</strong> {{portal_expires_at}}</p><p><strong>Chapter:</strong> {{chapter}}</p><p><strong>Date:</strong> {{current_date}}</p><p>Please keep this email for your records.</p>'
        ),
        'assignment_body_text' => ticket_config_value(
            'TICKET_ASSIGNMENT_EMAIL_TEXT',
            "Hello {{full_name}},\n\nYour ticket assignment is ready.\n\nTickets: {{ticket_list}}\nRanges: {{ticket_ranges}}\nCount: {{ticket_count}}\nActivation Link: {{activation_link}}\nQR: Use the QR code shown in the HTML version of this email.\nPortal Expires: {{portal_expires_at}}\nChapter: {{chapter}}\nDate: {{current_date}}\n\nPlease keep this email for your records."
        ),
            'chapter_invitation_format' => ticket_config_value('TICKET_CHAPTER_INVITATION_EMAIL_FORMAT', 'html'),
            'chapter_invitation_subject' => ticket_config_value('TICKET_CHAPTER_INVITATION_EMAIL_SUBJECT', 'Action Required: {{ticket_count}} Tickets Assigned to {{chapter_name}} Chapter - Please Distribute'),
            'chapter_invitation_body_html' => ticket_config_value(
                'TICKET_CHAPTER_INVITATION_EMAIL_HTML',
                '<div style="max-width:640px;margin:0 auto;background:#f4f7fb;padding:24px;font-family:Arial,sans-serif;color:#1f2937;"><div style="background:#ffffff;border:1px solid #dbe4f0;border-radius:14px;overflow:hidden;"><div style="background:linear-gradient(135deg,#1f3c68 0%,#2d5a92 100%);padding:22px 24px;"><p style="margin:0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#dbeafe;font-weight:700;">Chapter Ticket Distribution</p><h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;color:#ffffff;">Action Required: Please Distribute Your Assigned Tickets</h1></div><div style="padding:24px;"><p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hello {{chapter_name}} President,</p><p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Your chapter portal account is ready and <strong>{{ticket_count}} ticket(s)</strong> have been assigned to your chapter in range <strong>{{ticket_range_summary}}</strong>. Please log in and distribute these tickets to buyers or members.</p><div style="margin:0 0 20px;"><a href="{{portal_link}}" style="display:inline-block;background:#ff6b35;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px;">Open Chapter Dashboard</a></div><div style="border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:14px 16px;margin:0 0 20px;"><p style="margin:0 0 8px;font-size:13px;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Portal Credentials</p><p style="margin:0 0 6px;font-size:14px;"><strong>Username:</strong> {{username}}</p><p style="margin:0 0 6px;font-size:14px;"><strong>Password:</strong> {{password}}</p><p style="margin:0;font-size:12px;color:#92400e;">For security, change this password after your first login.</p></div><div style="border:1px solid #dbe4f0;background:#f8fbff;border-radius:10px;padding:16px;"><h2 style="margin:0 0 10px;font-size:16px;color:#1f3c68;">How to Use the Chapter Dashboard</h2><ol style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;"><li><strong>Log In:</strong> Use the provided username and password to access the Chapter Portal.</li><li><strong>Review Tickets:</strong> You will see all {{ticket_count}} assigned tickets for your chapter.</li><li><strong>Assign to Members:</strong> Enter the buyer or member name, email, and contact details for each ticket.</li><li><strong>Monitor Progress:</strong> Track which tickets have been distributed and which are still pending.</li></ol></div><p style="margin:18px 0 6px;font-size:13px;color:#6b7280;"><strong>Allocation Summary:</strong> {{ticket_count}} tickets, range {{ticket_range_summary}}</p><p style="margin:0;font-size:12px;color:#6b7280;">Issued on {{current_date}}</p></div></div></div>'
            ),
            'chapter_invitation_body_text' => ticket_config_value(
                'TICKET_CHAPTER_INVITATION_EMAIL_TEXT',
                "Action Required: {{ticket_count}} Tickets Assigned to {{chapter_name}} Chapter - Please Distribute\n\nHello {{chapter_name}} President,\n\nYour chapter portal account is ready. {{ticket_count}} ticket(s) were assigned to your chapter in range {{ticket_range_summary}}.\n\nPortal Link: {{portal_link}}\nUsername: {{username}}\nPassword: {{password}}\nDate: {{current_date}}\n\nHow to use the Chapter Dashboard:\n1. Log In: Use the provided username and password to access the Chapter Portal.\n2. Review Tickets: You will see all {{ticket_count}} assigned tickets for your chapter.\n3. Assign to Members: Enter buyer or member name, email, and contact details for each ticket.\n4. Monitor Progress: Track distributed tickets and pending tickets.\n\nPlease change your password after your first login."
            ),
        'activation_thankyou_format' => ticket_config_value('TICKET_ACTIVATION_THANKYOU_FORMAT', 'html'),
        'activation_thankyou_subject' => ticket_config_value('TICKET_ACTIVATION_THANKYOU_SUBJECT', 'Thank You for Activating {{activated_ticket_count}} Ticket(s)'),
        'activation_thankyou_body_html' => ticket_config_value(
            'TICKET_ACTIVATION_THANKYOU_HTML',
            '<p>Hello {{full_name}},</p><p>Thank you for activating your raffle ticket(s).</p><p><strong>Activated Ticket(s):</strong> {{activated_ticket_list}}</p><p><strong>Total Activated This Submission:</strong> {{activated_ticket_count}}</p><p><strong>Activation Date:</strong> {{activation_date}}</p><p>We appreciate your participation.</p>'
        ),
        'activation_thankyou_body_text' => ticket_config_value(
            'TICKET_ACTIVATION_THANKYOU_TEXT',
            "Hello {{full_name}},\n\nThank you for activating your raffle ticket(s).\nActivated Ticket(s): {{activated_ticket_list}}\nTotal Activated This Submission: {{activated_ticket_count}}\nActivation Date: {{activation_date}}\n\nWe appreciate your participation."
        ),
        'winner_contact_format' => ticket_config_value('TICKET_WINNER_CONTACT_FORMAT', 'html'),
        'winner_contact_subject' => ticket_config_value('TICKET_WINNER_CONTACT_SUBJECT', 'Winner Notification - {{prize_name}}'),
        'winner_contact_body_html' => ticket_config_value(
            'TICKET_WINNER_CONTACT_HTML',
            '<p>Hello {{full_name}},</p><p>Your ticket has been selected for <strong>{{prize_name}}</strong>.<activation_cutof/p><p><strong>Claim Deadline:</strong> {{claim_deadline}}</p><p><strong>Claim Contact:</strong> {{claim_contact}}</p><p>Please keep your activated ticket details ready.</p>'
        ),
        'winner_contact_body_text' => ticket_config_value(
            'TICKET_WINNER_CONTACT_TEXT',
            "Hello {{full_name}},\n\nYour ticket has been selected for {{prize_name}}.\nClaim Deadline: {{claim_deadline}}\nClaim Contact: {{claim_contact}}\n\nPlease keep your activated ticket details ready."
        ),
    ],
];
