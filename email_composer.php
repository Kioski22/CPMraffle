<?php

require_once __DIR__ . '/email_template_store.php';

if (!function_exists('ticket_template_decode_multiline')) {
    function ticket_template_decode_multiline(string $value): string
    {
        // Allow templates from env vars to use escaped newlines.
        return str_replace(["\\r\\n", "\\n", "\\r"], ["\r\n", "\n", "\r"], $value);
    }
}

if (!function_exists('ticket_email_guess_mime_type')) {
    function ticket_email_guess_mime_type(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return $detected;
                }
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 'image/jpeg';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }
        if ($ext === 'ico') {
            return 'image/x-icon';
        }

        return 'application/octet-stream';
    }
}

if (!function_exists('ticket_email_collect_attachment_parts')) {
    function ticket_email_collect_attachment_parts(array $templateConfig, string $prefix = ''): array
    {
        $pathKey = $prefix !== '' ? $prefix . '_attachment_path' : 'attachment_path';
        $nameKey = $prefix !== '' ? $prefix . '_attachment_filename' : 'attachment_filename';

        $attachmentPath = trim((string) ($templateConfig[$pathKey] ?? ''));
        $attachmentFilename = trim((string) ($templateConfig[$nameKey] ?? ''));
        if ($attachmentPath === '') {
            return [];
        }

        $isAbsolutePath = (bool) preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $attachmentPath);
        $resolvedPath = $isAbsolutePath ? $attachmentPath : (__DIR__ . '/' . ltrim($attachmentPath, '/\\'));
        if (!is_file($resolvedPath)) {
            return [];
        }

        $bytes = (string) file_get_contents($resolvedPath);
        if ($bytes === '') {
            return [];
        }

        if ($attachmentFilename === '') {
            $attachmentFilename = basename($resolvedPath);
        }

        return [[
            'filename' => $attachmentFilename,
            'content_type' => ticket_email_guess_mime_type($resolvedPath),
            'data' => $bytes,
        ]];
    }
}

if (!function_exists('ticket_email_resolve_local_asset_path')) {
    function ticket_email_resolve_local_asset_path(string $source, array $appConfig): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        $candidates = [];
        if (preg_match('#^https?://#i', $source)) {
            $path = (string) (parse_url($source, PHP_URL_PATH) ?? '');
            $basePath = (string) (parse_url((string) ($appConfig['app']['base_url'] ?? ''), PHP_URL_PATH) ?? '');
            if ($path !== '') {
                if ($basePath !== '' && strpos($path, $basePath) === 0) {
                    $relative = substr($path, strlen($basePath));
                    $candidates[] = __DIR__ . '/' . ltrim((string) $relative, '/');
                }
                $candidates[] = __DIR__ . '/' . ltrim($path, '/');
                $candidates[] = __DIR__ . '/' . basename($path);
            }
        } else {
            $candidates[] = __DIR__ . '/' . ltrim($source, '/\\');
            $candidates[] = __DIR__ . '/' . basename($source);
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('ticket_email_inline_logo_assets')) {
    /**
     * Resolve configured header logos into cid sources + inline MIME parts.
     * Falls back to original URL if local file cannot be resolved.
     */
    function ticket_email_inline_logo_assets(array $appConfig): array
    {
        $leftUrl = trim((string) ($appConfig['app']['header_logo_left_url'] ?? ''));
        $rightUrl = trim((string) ($appConfig['app']['header_logo_right_url'] ?? ''));

        $result = [
            'left_src' => $leftUrl,
            'right_src' => $rightUrl,
            'images' => [],
        ];

        $leftPath = ticket_email_resolve_local_asset_path($leftUrl, $appConfig);
        if ($leftPath !== '') {
            $bytes = (string) file_get_contents($leftPath);
            if ($bytes !== '') {
                $result['left_src'] = 'cid:header_logo_left';
                $result['images'][] = [
                    'cid' => 'header_logo_left',
                    'png_data' => $bytes,
                    'filename' => basename($leftPath),
                    'content_type' => ticket_email_guess_mime_type($leftPath),
                ];
            }
        }

        $rightPath = ticket_email_resolve_local_asset_path($rightUrl, $appConfig);
        if ($rightPath !== '') {
            $bytes = (string) file_get_contents($rightPath);
            if ($bytes !== '') {
                $result['right_src'] = 'cid:header_logo_right';
                $result['images'][] = [
                    'cid' => 'header_logo_right',
                    'png_data' => $bytes,
                    'filename' => basename($rightPath),
                    'content_type' => ticket_email_guess_mime_type($rightPath),
                ];
            }
        }

        return $result;
    }
}

if (!function_exists('ticket_render_placeholders')) {
    function ticket_render_placeholders(string $template, array $variables): string
    {
        return preg_replace_callback('/{{\\s*([a-zA-Z0-9_]+)\\s*}}/', function (array $matches) use ($variables): string {
            $key = strtolower($matches[1]);
            return isset($variables[$key]) ? (string) $variables[$key] : '';
        }, $template) ?? $template;
    }
}

if (!function_exists('ticket_build_assignment_template_data')) {
    function ticket_build_assignment_template_data(array $input): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $chapter = trim((string) ($input['chapter'] ?? ''));
        $buyerType = trim((string) ($input['buyer_type'] ?? ''));
        $ticketList = trim((string) (($input['ticket_list'] ?? ($input['tickets'] ?? ''))));

        $ticketItems = [];
        if ($ticketList !== '') {
            foreach (explode(',', $ticketList) as $ticketItem) {
                $normalized = trim($ticketItem);
                if ($normalized !== '') {
                    $ticketItems[] = $normalized;
                }
            }
        }

        return [
            'full_name' => $fullName,
            'email' => $email,
            'chapter' => $chapter,
            'buyer_type' => $buyerType,
            'ticket_list' => $ticketList,
            'ticket_ranges' => trim((string) ($input['ticket_ranges'] ?? $ticketList)),
            'ticket_count' => (string) count($ticketItems),
            'first_ticket' => $ticketItems[0] ?? '',
            'last_ticket' => $ticketItems !== [] ? $ticketItems[count($ticketItems) - 1] : '',
            'activation_link' => trim((string) ($input['activation_link'] ?? '')),
            'activation_qr_cid' => trim((string) ($input['activation_qr_cid'] ?? '')),
            'portal_expires_at' => trim((string) ($input['portal_expires_at'] ?? '')),
            'header_logo_left_url' => trim((string) ($input['header_logo_left_url'] ?? '')),
            'header_logo_right_url' => trim((string) ($input['header_logo_right_url'] ?? '')),
            'current_date' => date('Y-m-d H:i:s'),
            'app_name' => trim((string) ($input['app_name'] ?? 'Ticketing Team')),
        ];
    }
}

if (!function_exists('ticket_build_activation_template_data')) {
    function ticket_build_activation_template_data(array $input): array
    {
        $activatedTicketList = trim((string) ($input['activated_ticket_list'] ?? ''));
        $activatedTickets = [];
        if ($activatedTicketList !== '') {
            foreach (explode(',', $activatedTicketList) as $ticketItem) {
                $normalized = trim($ticketItem);
                if ($normalized !== '') {
                    $activatedTickets[] = $normalized;
                }
            }
        }

        return [
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'contact_number' => trim((string) ($input['contact_number'] ?? '')),
            'activated_ticket_list' => $activatedTicketList,
            'activated_ticket_count' => (string) count($activatedTickets),
            'activation_date' => trim((string) ($input['activation_date'] ?? date('Y-m-d H:i:s'))),
            'app_name' => trim((string) ($input['app_name'] ?? 'Ticketing Team')),
            'prize_name' => trim((string) ($input['prize_name'] ?? '')),
            'claim_deadline' => trim((string) ($input['claim_deadline'] ?? '')),
            'claim_contact' => trim((string) ($input['claim_contact'] ?? '')),
        ];
    }
}

if (!function_exists('ticket_build_chapter_invitation_template_data')) {
    function ticket_build_chapter_invitation_template_data(array $input): array
    {
        return [
            'chapter_name' => trim((string) ($input['chapter_name'] ?? '')),
            'president_email' => trim((string) ($input['president_email'] ?? '')),
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => trim((string) ($input['password'] ?? '')),
            'portal_link' => trim((string) ($input['portal_link'] ?? '')),
            'ticket_range_summary' => trim((string) ($input['ticket_range_summary'] ?? '')),
            'ticket_count' => trim((string) ($input['ticket_count'] ?? '')),
            'current_date' => trim((string) ($input['current_date'] ?? date('Y-m-d H:i:s'))),
            'app_name' => trim((string) ($input['app_name'] ?? 'Ticketing Team')),
        ];
    }
}

if (!function_exists('ticket_chapter_portal_login_link')) {
    function ticket_chapter_portal_login_link(array $appConfig): string
    {
        $baseUrl = rtrim((string) ($appConfig['app']['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return '/CPMraffle/chapter_login.php';
        }

        return $baseUrl . '/chapter_login.php';
    }
}

if (!function_exists('ticket_compose_template_email')) {
    function ticket_compose_template_email(array $appConfig, string $prefix, array $templateData, array $defaults): array
    {
        $templateConfig = $appConfig['email_template'] ?? [];
        static $dbTemplateConfig = null;
        if ($dbTemplateConfig === null) {
            $dbTemplateConfig = [];
            global $conn;
            if (isset($conn) && $conn instanceof mysqli) {
                try {
                    $dbTemplateConfig = ticket_load_db_email_templates($conn, $appConfig);
                } catch (Throwable $exception) {
                    $dbTemplateConfig = [];
                }
            }
        }

        if ($dbTemplateConfig !== []) {
            $templateConfig = array_merge($templateConfig, $dbTemplateConfig);
        }

        $attachments = ticket_email_collect_attachment_parts($templateConfig, $prefix);

        $format = strtolower(trim((string) ($templateConfig[$prefix . '_format'] ?? ($defaults['format'] ?? 'html'))));
        if (!in_array($format, ['html', 'text'], true)) {
            $format = 'html';
        }

        $subjectTemplate = (string) ($templateConfig[$prefix . '_subject'] ?? ($defaults['subject'] ?? ''));
        $htmlTemplate = ticket_template_decode_multiline((string) ($templateConfig[$prefix . '_body_html'] ?? ($defaults['html'] ?? '')));
        $textTemplate = ticket_template_decode_multiline((string) ($templateConfig[$prefix . '_body_text'] ?? ($defaults['text'] ?? '')));

        $rawVars = [];
        $htmlVars = [];
        foreach ($templateData as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $stringValue = (string) $value;
            $rawVars[$normalizedKey] = $stringValue;
            $htmlVars[$normalizedKey] = htmlspecialchars($stringValue, ENT_QUOTES, 'UTF-8');
        }

        // Allow specific HTML-only placeholders to render raw markup in HTML templates.
        if (isset($rawVars['ticket_image'])) {
            $htmlVars['ticket_image'] = $rawVars['ticket_image'];
        }

        $subject = ticket_render_placeholders($subjectTemplate, $rawVars);
        if ($format === 'text') {
            $body = ticket_render_placeholders($textTemplate, $rawVars);
            $result = [
                'subject' => $subject,
                'body' => $body,
                'format' => 'text',
                'content_type' => 'text/plain; charset=UTF-8',
            ];
            if ($attachments !== []) {
                $result['attachments'] = $attachments;
            }

            return $result;
        }

        $body = ticket_render_placeholders($htmlTemplate, $htmlVars);
        $result = [
            'subject' => $subject,
            'body' => $body,
            'format' => 'html',
            'content_type' => 'text/html; charset=UTF-8',
        ];
        if ($attachments !== []) {
            $result['attachments'] = $attachments;
        }

        return $result;
    }
}

if (!function_exists('ticket_compose_assignment_email')) {
    function ticket_compose_assignment_email(array $appConfig, array $templateData): array
    {
        return ticket_compose_template_email($appConfig, 'assignment', $templateData, [
            'format' => 'html',
            'subject' => 'Your Ticket Assignment',
            'html' => '<div style="margin-bottom:16px;"><table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 10px;"><tr><td style="text-align:left;"><img src="{{header_logo_left_url}}" alt="Left Logo" style="max-height:56px;width:auto;display:block;"></td><td style="text-align:right;"><img src="{{header_logo_right_url}}" alt="Right Logo" style="max-height:56px;width:auto;display:block;margin-left:auto;"></td></tr></table><p style="margin:0 0 8px;">Hello {{full_name}},</p>{{ticket_image}}</div><p>Your ticket assignment is ready.</p><p><strong>Tickets:</strong> {{ticket_list}}</p><p><strong>Ranges:</strong> {{ticket_ranges}}</p><div style="background:#f4f7fb;border:1px solid #dbe4f0;border-radius:10px;padding:20px;text-align:center;margin:20px 0;"><h3 style="margin-top:0;color:#1f3c68;">Activate Your Tickets</h3><p style="margin-bottom:20px;"><a href="{{activation_link}}" style="display:inline-block;background:#ff6b35;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:8px;">Click Here to Activate</a></p><p style="margin:0 0 10px;color:#6b7280;font-size:14px;">Or scan this QR code with your phone:</p><img src="{{activation_qr_cid}}" alt="Activation QR Code" style="max-width:150px;height:auto;border:1px solid #d1d5db;border-radius:8px;padding:6px;background:#ffffff;"></div><p><strong>Portal expires:</strong> {{portal_expires_at}}</p><p>Please keep this email for your records.</p>',
            'text' => "Hello {{full_name}},\n\nYour ticket assignment is ready.\nTickets: {{ticket_list}}\nRanges: {{ticket_ranges}}\nActivate here: {{activation_link}}\nOr scan the QR code shown in the HTML version of this email.\nPortal expires: {{portal_expires_at}}\n\nPlease keep this email for your records.",
        ]);
    }
}

if (!function_exists('ticket_compose_activation_thankyou_email')) {
    function ticket_compose_activation_thankyou_email(array $appConfig, array $templateData): array
    {
        return ticket_compose_template_email($appConfig, 'activation_thankyou', $templateData, [
            'format' => 'html',
            'subject' => 'Thank You for Activating Your Ticket(s)',
            'html' => '<p>Hello {{full_name}},</p><p>Thank you for activating your raffle ticket(s).</p><p><strong>Activated Ticket(s):</strong> {{activated_ticket_list}}</p><p><strong>Total Activated This Submission:</strong> {{activated_ticket_count}}</p><p><strong>Activation Date:</strong> {{activation_date}}</p><p>We appreciate your participation.</p>',
            'text' => "Hello {{full_name}},\n\nThank you for activating your raffle ticket(s).\nActivated Ticket(s): {{activated_ticket_list}}\nTotal Activated This Submission: {{activated_ticket_count}}\nActivation Date: {{activation_date}}\n\nWe appreciate your participation.",
        ]);
    }
}

if (!function_exists('ticket_compose_chapter_invitation_email')) {
    function ticket_compose_chapter_invitation_email(array $appConfig, array $templateData): array
    {
        // Force chapter invitation emails to always use the chapter login page.
        $templateData['portal_link'] = ticket_chapter_portal_login_link($appConfig);

        return ticket_compose_template_email($appConfig, 'chapter_invitation', $templateData, [
            'format' => 'html',
            'subject' => 'Action Required: {{ticket_count}} Tickets Assigned to {{chapter_name}} Chapter - Please Distribute',
            'html' => '<div style="max-width:640px;margin:0 auto;background:#f4f7fb;padding:24px;font-family:Arial,sans-serif;color:#1f2937;"><div style="background:#ffffff;border:1px solid #dbe4f0;border-radius:14px;overflow:hidden;"><div style="background:linear-gradient(135deg,#1f3c68 0%,#2d5a92 100%);padding:22px 24px;"><p style="margin:0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#dbeafe;font-weight:700;">Chapter Ticket Distribution</p><h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;color:#ffffff;">Action Required: Please Distribute Your Assigned Tickets</h1></div><div style="padding:24px;"><p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hello {{chapter_name}} President,</p><p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Your chapter portal account is ready and <strong>{{ticket_count}} ticket(s)</strong> have been assigned to your chapter in range <strong>{{ticket_range_summary}}</strong>. Please log in and distribute these tickets to buyers or members.</p><div style="margin:0 0 20px;"><a href="{{portal_link}}" style="display:inline-block;background:#ff6b35;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px;">Open Chapter Dashboard</a></div><div style="border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:14px 16px;margin:0 0 20px;"><p style="margin:0 0 8px;font-size:13px;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Portal Credentials</p><p style="margin:0 0 6px;font-size:14px;"><strong>Username:</strong> {{username}}</p><p style="margin:0 0 6px;font-size:14px;"><strong>Password:</strong> {{password}}</p><p style="margin:0;font-size:12px;color:#92400e;">For security, change this password after your first login.</p></div><div style="border:1px solid #dbe4f0;background:#f8fbff;border-radius:10px;padding:16px;"><h2 style="margin:0 0 10px;font-size:16px;color:#1f3c68;">How to Use the Chapter Dashboard</h2><ol style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;"><li><strong>Log In:</strong> Use the provided username and password to access the Chapter Portal.</li><li><strong>Review Tickets:</strong> You will see all {{ticket_count}} assigned tickets for your chapter.</li><li><strong>Assign to Members:</strong> Enter the buyer or member name, email, and contact details for each ticket.</li><li><strong>Monitor Progress:</strong> Track which tickets have been distributed and which are still pending.</li></ol></div><p style="margin:18px 0 6px;font-size:13px;color:#6b7280;"><strong>Allocation Summary:</strong> {{ticket_count}} tickets, range {{ticket_range_summary}}</p><p style="margin:0;font-size:12px;color:#6b7280;">Issued on {{current_date}}</p></div></div></div>',
            'text' => "Action Required: {{ticket_count}} Tickets Assigned to {{chapter_name}} Chapter - Please Distribute\n\nHello {{chapter_name}} President,\n\nYour chapter portal account is ready. {{ticket_count}} ticket(s) were assigned to your chapter in range {{ticket_range_summary}}.\n\nPortal Link: {{portal_link}}\nUsername: {{username}}\nPassword: {{password}}\nDate: {{current_date}}\n\nHow to use the Chapter Dashboard:\n1. Log In: Use the provided username and password to access the Chapter Portal.\n2. Review Tickets: You will see all {{ticket_count}} assigned tickets for your chapter.\n3. Assign to Members: Enter buyer or member name, email, and contact details for each ticket.\n4. Monitor Progress: Track distributed tickets and pending tickets.\n\nPlease change your password after your first login.",
        ]);
    }
}

if (!function_exists('ticket_compose_winner_email')) {
    function ticket_compose_winner_email(array $appConfig, array $templateData): array
    {
        return ticket_compose_template_email($appConfig, 'winner_contact', $templateData, [
            'format' => 'html',
            'subject' => 'Winner Notification - {{prize_name}}',
            'html' => '<p>Hello {{full_name}},</p><p>Your ticket has been selected for <strong>{{prize_name}}</strong>.</p><p><strong>Claim Deadline:</strong> {{claim_deadline}}</p><p><strong>Claim Contact:</strong> {{claim_contact}}</p><p>Please keep your activated ticket details ready.</p>',
            'text' => "Hello {{full_name}},\n\nYour ticket has been selected for {{prize_name}}.\nClaim Deadline: {{claim_deadline}}\nClaim Contact: {{claim_contact}}\n\nPlease keep your activated ticket details ready.",
        ]);
    }
}

if (!function_exists('ticket_build_mime_message')) {
    /**
     * Build a raw MIME message string ready for base64url-encoding and sending via Gmail API.
     *
     * When $inlineImages is empty the message is a simple single-part message (original behaviour).
     * When images are supplied it becomes a multipart/related message so each image can be referenced
     * by a Content-ID (cid:) URI inside the HTML body.
     *
     * @param array $inlineImages  Each element must contain 'cid', 'png_data', and 'filename'.
     */
    function ticket_build_mime_message(
        string $fromAddress,
        string $fromName,
        string $toAddress,
        string $toName,
        string $subject,
        string $body,
        string $contentType,
        array  $inlineImages = [],
        array  $attachments = []
    ): string {
        $safeFromName = str_replace(["\r", "\n"], '', $fromName);
        $safeToName   = str_replace(["\r", "\n"], '', $toName);

        $headers  = '';
        $headers .= 'From: ' . $safeFromName . ' <' . $fromAddress . '>' . "\r\n";
        $headers .= 'To: ' . $safeToName . ' <' . $toAddress . '>' . "\r\n";
        $headers .= 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if ($inlineImages === [] && $attachments === []) {
            // Simple single-part message — preserve original behaviour.
            return $headers . 'Content-Type: ' . $contentType . "\r\n\r\n" . $body;
        }

        if ($attachments === []) {
            // multipart/related so CID images are recognised by email clients.
            $boundary = 'ticket_related_' . bin2hex(random_bytes(8));
            $mimeMessage  = $headers;
            $mimeMessage .= 'Content-Type: multipart/related; boundary="' . $boundary . '"' . "\r\n\r\n";

            // Part 1 — email body.
            $mimeMessage .= '--' . $boundary . "\r\n";
            $mimeMessage .= 'Content-Type: ' . $contentType . "\r\n\r\n";
            $mimeMessage .= $body . "\r\n";

            // Subsequent parts — inline images.
            foreach ($inlineImages as $img) {
                $cid      = (string) ($img['cid']      ?? '');
                $filename = str_replace(["\r", "\n", '"'], '', (string) ($img['filename'] ?? 'ticket.png'));
                $pngData  = (string) ($img['png_data'] ?? '');
                $mimeType = (string) ($img['content_type'] ?? 'image/png');
                if ($cid === '' || $pngData === '') {
                    continue;
                }
                $encoded = chunk_split(base64_encode($pngData), 76, "\r\n");
                $mimeMessage .= '--' . $boundary . "\r\n";
                $mimeMessage .= 'Content-Type: ' . $mimeType . '; name="' . $filename . '"' . "\r\n";
                $mimeMessage .= "Content-Transfer-Encoding: base64\r\n";
                $mimeMessage .= 'Content-ID: <' . $cid . '>' . "\r\n";
                $mimeMessage .= 'Content-Disposition: inline; filename="' . $filename . '"' . "\r\n\r\n";
                $mimeMessage .= $encoded;
            }

            $mimeMessage .= '--' . $boundary . '--' . "\r\n";
            return $mimeMessage;
        }

        // Attachments require multipart/mixed with body as first part.
        $mixedBoundary = 'ticket_mixed_' . bin2hex(random_bytes(8));
        $mimeMessage  = $headers;
        $mimeMessage .= 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"' . "\r\n\r\n";

        if ($inlineImages !== []) {
            $relatedBoundary = 'ticket_related_' . bin2hex(random_bytes(8));
            $mimeMessage .= '--' . $mixedBoundary . "\r\n";
            $mimeMessage .= 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"' . "\r\n\r\n";

            $mimeMessage .= '--' . $relatedBoundary . "\r\n";
            $mimeMessage .= 'Content-Type: ' . $contentType . "\r\n\r\n";
            $mimeMessage .= $body . "\r\n";

            foreach ($inlineImages as $img) {
                $cid      = (string) ($img['cid']      ?? '');
                $filename = str_replace(["\r", "\n", '"'], '', (string) ($img['filename'] ?? 'ticket.png'));
                $pngData  = (string) ($img['png_data'] ?? '');
                $mimeType = (string) ($img['content_type'] ?? 'image/png');
                if ($cid === '' || $pngData === '') {
                    continue;
                }
                $encoded = chunk_split(base64_encode($pngData), 76, "\r\n");
                $mimeMessage .= '--' . $relatedBoundary . "\r\n";
                $mimeMessage .= 'Content-Type: ' . $mimeType . '; name="' . $filename . '"' . "\r\n";
                $mimeMessage .= "Content-Transfer-Encoding: base64\r\n";
                $mimeMessage .= 'Content-ID: <' . $cid . '>' . "\r\n";
                $mimeMessage .= 'Content-Disposition: inline; filename="' . $filename . '"' . "\r\n\r\n";
                $mimeMessage .= $encoded;
            }

            $mimeMessage .= '--' . $relatedBoundary . '--' . "\r\n";
        } else {
            $mimeMessage .= '--' . $mixedBoundary . "\r\n";
            $mimeMessage .= 'Content-Type: ' . $contentType . "\r\n\r\n";
            $mimeMessage .= $body . "\r\n";
        }

        foreach ($attachments as $attachment) {
            $filename = str_replace(["\r", "\n", '"'], '', (string) ($attachment['filename'] ?? 'attachment.bin'));
            $data = (string) ($attachment['data'] ?? '');
            if ($data === '') {
                continue;
            }

            $mimeType = trim((string) ($attachment['content_type'] ?? 'application/octet-stream'));
            if ($mimeType === '') {
                $mimeType = 'application/octet-stream';
            }

            $encoded = chunk_split(base64_encode($data), 76, "\r\n");
            $mimeMessage .= '--' . $mixedBoundary . "\r\n";
            $mimeMessage .= 'Content-Type: ' . $mimeType . '; name="' . $filename . '"' . "\r\n";
            $mimeMessage .= "Content-Transfer-Encoding: base64\r\n";
            $mimeMessage .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
            $mimeMessage .= $encoded;
        }

        $mimeMessage .= '--' . $mixedBoundary . '--' . "\r\n";
        return $mimeMessage;
    }
}
