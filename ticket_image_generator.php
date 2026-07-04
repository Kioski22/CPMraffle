<?php

require_once __DIR__ . '/ticket_layout_helper.php';

if (!function_exists('ticket_image_qr_library_available')) {
    function ticket_image_qr_library_available(): bool
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

if (!function_exists('ticket_generate_single_ticket_png')) {
    /**
     * Render one raffle ticket as a PNG image using the saved layout configuration.
     *
     * @param array  $layout        Layout array from ticket_layout_load().
     * @param string $displayText   The ticket number/range text to print on the ticket.
     * @param string $qrImagePath   Absolute filesystem path to a pre-generated QR PNG, or '' to skip.
     * @return string               Raw PNG binary data.
     */
    function ticket_generate_single_ticket_png(array $layout, string $displayText, string $qrImagePath): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required for ticket image generation. Enable the php_gd2 extension in php.ini.');
        }

        // Render at 150 DPI so text stays crisp in email clients.
        $dpi   = 150;
        $scale = $dpi / 25.4; // pixels per mm

        $bgPath = __DIR__ . '/' . ltrim((string) $layout['background_image'], '/\\');
        if (!is_file($bgPath)) {
            throw new RuntimeException('Ticket background image not found: ' . $bgPath);
        }

        $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));
        $bg  = null;
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $bg = @imagecreatefromjpeg($bgPath);
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $bg = @imagecreatefromwebp($bgPath);
        } else {
            $bg = @imagecreatefrompng($bgPath);
        }
        if (!$bg) {
            throw new RuntimeException('Unable to load ticket background image.');
        }

        $widthPx  = (int) round((float) $layout['page_width_mm']  * $scale);
        $heightPx = (int) round((float) $layout['page_height_mm'] * $scale);

        $canvas = imagecreatetruecolor($widthPx, $heightPx);
        // Enable alpha blending so PNG backgrounds render correctly.
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $bg, 0, 0, 0, 0, $widthPx, $heightPx, imagesx($bg), imagesy($bg));
        imagedestroy($bg);

        // Overlay QR code.
        if ($qrImagePath !== '' && is_file($qrImagePath)) {
            $qr = @imagecreatefrompng($qrImagePath);
            if ($qr) {
                $qrSizePx = (int) round((float) $layout['qr_size_mm'] * $scale);
                $qrX      = (int) round((float) $layout['qr_x_mm']    * $scale);
                $qrY      = (int) round((float) $layout['qr_y_mm']    * $scale);
                imagecopyresampled($canvas, $qr, $qrX, $qrY, 0, 0, $qrSizePx, $qrSizePx, imagesx($qr), imagesy($qr));
                imagedestroy($qr);
            }
        }

        // Render text.
        $colorRgb  = ticket_layout_color_rgb((string) $layout['text_color_hex']);
        $textColor = imagecolorallocate($canvas, $colorRgb[0], $colorRgb[1], $colorRgb[2]);
        $fontSizePt = max(1.0, (float) $layout['text_font_size_pt']);
        // Scale pt to pixels at our render DPI (1pt = 1/72 inch).
        $scaledPt   = $fontSizePt * ($dpi / 72.0);
        $textXMm    = (float) $layout['text_x_mm'];
        $textYMm    = (float) $layout['text_y_mm'];
        $textXPx    = (int) round($textXMm * $scale);
        $textYPx    = (int) round($textYMm * $scale);
        $textAlign  = (string) ($layout['text_align'] ?? 'left');

        // TTF font candidates: Windows then Linux.
        $fontCandidates = [
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\Arial.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
        ];
        $fontPath = '';
        foreach ($fontCandidates as $candidate) {
            if (is_file($candidate)) {
                $fontPath = $candidate;
                break;
            }
        }

        if ($fontPath !== '' && function_exists('imagettfbbox') && function_exists('imagettftext')) {
            $bbox      = @imagettfbbox($scaledPt, 0, $fontPath, $displayText) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $textWidth = (int) abs(($bbox[4] ?? 0) - ($bbox[0] ?? 0));
            if ($textAlign === 'center') {
                $textXPx = (int) round(($widthPx - $textWidth) / 2);
            } elseif ($textAlign === 'right') {
                $textXPx = $widthPx - $textXPx - $textWidth;
            }
            // imagettftext y is baseline, not top-left — $textYPx already in pixel space.
            @imagettftext($canvas, $scaledPt, 0, $textXPx, $textYPx, $textColor, $fontPath, $displayText);
        } else {
            // Built-in font fallback.
            $builtinFont = min(5, max(1, (int) round($scaledPt / 8)));
            $charW       = (int) imagefontwidth($builtinFont);
            $charH       = (int) imagefontheight($builtinFont);
            $textWidth   = $charW * strlen($displayText);
            if ($textAlign === 'center') {
                $textXPx = (int) round(($widthPx - $textWidth) / 2);
            } elseif ($textAlign === 'right') {
                $textXPx = $widthPx - $textXPx - $textWidth;
            }
            // imagestring y is top of character.
            $adjustedY = $textYPx - (int) round($charH / 2);
            imagestring($canvas, $builtinFont, $textXPx, max(0, $adjustedY), $displayText, $textColor);
        }

        ob_start();
        imagepng($canvas, null, 7); // compression level 7 — good balance for email
        $pngBytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $pngBytes;
    }
}

if (!function_exists('ticket_generate_buyer_email_images')) {
    /**
     * Generate one or more ticket PNG images ready for embedding in an email.
     *
    * Email output is always a single combined ticket image so templates remain consistent.
    * For one ticket, the text is the single ticket number.
    * For multiple tickets, the text uses a range/list representation.
     *
     * @param array  $layout         Layout config from ticket_layout_load().
     * @param array  $ticketNumbers  Ticket numbers assigned to this buyer.
     * @param string $qrTargetLink   URL encoded into the ticket QR placeholder.
     * @return array  Each element: ['cid' => string, 'png_data' => string, 'ticket_number' => string, 'filename' => string]
     */
    function ticket_generate_buyer_email_images(array $layout, array $ticketNumbers, string $qrTargetLink): array
    {
        $qrCacheDir = __DIR__ . '/uploads/buyer_pdfs/qr_cache';
        if (!is_dir($qrCacheDir)) {
            mkdir($qrCacheDir, 0777, true);
        }

        $qrPath = '';
        if ($qrTargetLink !== '') {
            $qrPath = $qrCacheDir . '/' . sha1($qrTargetLink) . '.png';
            if (!is_file($qrPath) && ticket_image_qr_library_available()) {
                QRcode::png($qrTargetLink, $qrPath);
            }
            if (!is_file($qrPath)) {
                $qrPath = '';
            }
        }

        $images = [];

        $displayLayout = $layout;
        $displayLayout['text_mode'] = count($ticketNumbers) <= 1 ? 'ticket_number' : 'ticket_range';
        $displayText = ticket_layout_display_text($displayLayout, $ticketNumbers);
        $cid         = 'ticket_group_' . substr(md5(implode(',', $ticketNumbers)), 0, 12);
        $pngData     = ticket_generate_single_ticket_png($layout, $displayText, $qrPath);
        $images[]    = [
            'ticket_number' => $displayText,
            'cid'           => $cid,
            'png_data'      => $pngData,
            'filename'      => 'raffle_ticket.png',
        ];

        return $images;
    }
}
