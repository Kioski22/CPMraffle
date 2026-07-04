<?php

require_once __DIR__ . '/buyer_activation_helper.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/ticket_mailer.php';

if (!function_exists('ticket_assignment_qr_library_available')) {
    function ticket_assignment_qr_library_available(): bool
    {
        if (class_exists('QRcode')) {
            return true;
        }

        $qrLibPath = __DIR__ . '/phpqrcode/qrlib.php';
        if (is_file($qrLibPath)) {
            require_once $qrLibPath;
        }

        return class_exists('QRcode');
    }
}

if (!function_exists('ticket_assignment_qr_fallback_url')) {
    function ticket_assignment_qr_fallback_url(string $activationLink): string
    {
        $link = trim($activationLink);
        if ($link === '') {
            return '';
        }

        return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($link);
    }
}

if (!function_exists('ticket_send_buyer_assignment_email')) {
    function ticket_send_buyer_assignment_email(mysqli $conn, array $appConfig, array $buyer, array $ticketNumbers): array
    {
        $buyerId = (int) ($buyer['id'] ?? 0);
        if ($buyerId <= 0) {
            throw new RuntimeException('Missing buyer id for assignment email.');
        }

        $activationData = ticket_buyer_activation_link_data($conn, $appConfig, $buyerId);
        $activationLink = trim((string) ($activationData['activation_link'] ?? ''));
        $portalExpiresAt = trim((string) ($activationData['expires_at'] ?? ''));
        if ($activationLink === '') {
            throw new RuntimeException('Unable to resolve buyer activation link.');
        }
        $ticketList = implode(', ', $ticketNumbers);
        $ticketRanges = ticket_format_number_ranges($ticketNumbers);

        $mailConfig = $appConfig['mail'] ?? [];
        $templateData = ticket_build_assignment_template_data([
            'full_name' => (string) ($buyer['full_name'] ?? ''),
            'email' => (string) ($buyer['email'] ?? ''),
            'chapter' => (string) ($buyer['chapter'] ?? ''),
            'buyer_type' => (string) ($buyer['buyer_type'] ?? ''),
            'ticket_list' => $ticketList,
            'ticket_ranges' => $ticketRanges,
            'activation_link' => $activationLink,
            'portal_expires_at' => $portalExpiresAt,
            'header_logo_left_url' => '',
            'header_logo_right_url' => '',
            'app_name' => (string) ($mailConfig['from_name'] ?? 'Ticketing Team'),
        ]);

        $templateData['activation_qr_cid'] = ticket_assignment_qr_fallback_url($activationLink);

        // Optionally embed inline ticket images when the admin has enabled the toggle.
        $assignmentTemplate = ticket_fetch_email_template_record($conn, $appConfig, 'assignment');
        $templateData['ticket_image'] = '';
        $inlineImages = [];

        try {
            if (ticket_assignment_qr_library_available()) {
                ob_start();
                QRcode::png($activationLink, null, QR_ECLEVEL_M, 4, 2);
                $qrBytes = (string) ob_get_clean();
                if ($qrBytes !== '') {
                    $qrCid = 'activation_qr_code';
                    $inlineImages[] = [
                        'cid' => $qrCid,
                        'png_data' => $qrBytes,
                        'filename' => 'activation-qr.png',
                        'content_type' => 'image/png',
                    ];
                    $templateData['activation_qr_cid'] = 'cid:' . $qrCid;
                }
            }
        } catch (Throwable $qrException) {
            // Non-fatal — email still sends with regular activation link if QR creation fails.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        if (!empty($assignmentTemplate['attach_ticket_layout'])) {
            try {
                require_once __DIR__ . '/ticket_image_generator.php';
                require_once __DIR__ . '/ticket_layout_helper.php';
                $layout = ticket_layout_load();
                $ticketImages = ticket_generate_buyer_email_images($layout, $ticketNumbers, $activationLink);
                if ($ticketImages !== []) {
                    $imgHtml  = '<div style="margin:0 0 14px;">';
                    $imgHtml .= '<p style="font-family:sans-serif;font-size:14px;font-weight:600;color:#374151;margin:0 0 8px;">Your Raffle Ticket(s):</p>';
                    foreach ($ticketImages as $img) {
                        $safeCid = htmlspecialchars((string) ($img['cid'] ?? ''), ENT_QUOTES);
                        $safeTn  = htmlspecialchars((string) ($img['ticket_number'] ?? ''), ENT_QUOTES);
                        $imgHtml .= '<img src="cid:' . $safeCid . '" alt="Raffle Ticket ' . $safeTn . '"'
                            . ' style="display:block;max-width:100%;height:auto;border:1px solid #d1d5db;border-radius:8px;margin:8px 0;">';
                    }
                    $imgHtml .= '</div>';
                    $templateData['ticket_image'] = $imgHtml;
                    $inlineImages = array_merge($inlineImages, $ticketImages);
                }
            } catch (Throwable $imgException) {
                // Non-fatal — email still sends without the ticket image if GD/generation fails.
            }
        }

        $emailParts = ticket_compose_assignment_email($appConfig, $templateData);
        if (strtolower((string) ($emailParts['format'] ?? 'html')) === 'html') {
            if ($inlineImages !== []) {
                $emailParts['inline_images'] = $inlineImages;
            }
        }

        $deliveryMethod = ticket_send_gmail_message(
            $appConfig,
            (string) ($buyer['full_name'] ?? ''),
            (string) ($buyer['email'] ?? ''),
            $emailParts
        );

        return [
            'delivery_method' => $deliveryMethod,
            'activation_link' => $activationLink,
            'portal_expires_at' => $portalExpiresAt,
            'ticket_ranges' => $ticketRanges,
        ];
    }
}

if (!function_exists('send_buyer_assignment_email')) {
    function send_buyer_assignment_email(mysqli $conn, array $appConfig, array $buyer, array $ticketNumbers): array
    {
        return ticket_send_buyer_assignment_email($conn, $appConfig, $buyer, $ticketNumbers);
    }
}
