<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/buyer_activation_helper.php';
require_once __DIR__ . '/ticket_layout_helper.php';
require_once __DIR__ . '/fpdf/fpdf.php';

$buyerPdfAppConfig = require __DIR__ . '/config.php';

if (!function_exists('buyer_pdf_output_path')) {
    function buyer_pdf_output_path(int $buyerId): string
    {
        return __DIR__ . '/uploads/buyer_pdfs/buyer_' . $buyerId . '.pdf';
    }
}

if (!function_exists('buyer_pdf_filename')) {
    function buyer_pdf_filename(array $buyer, array $ticketNumbers): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($buyer['full_name'] ?? 'buyer'));
        $summary = function_exists('ticket_format_number_ranges') ? ticket_format_number_ranges($ticketNumbers) : implode('_', $ticketNumbers);
        $summary = preg_replace('/[^A-Za-z0-9_-]+/', '_', $summary);
        return trim($safeName . '_' . $summary, '_') . '.pdf';
    }
}

if (!function_exists('buyer_pdf_qr_cache_directory')) {
    function buyer_pdf_qr_cache_directory(): string
    {
        return __DIR__ . '/uploads/buyer_pdfs/qr_cache';
    }
}

if (!function_exists('buyer_pdf_qr_library_available')) {
    function buyer_pdf_qr_library_available(): bool
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

if (!function_exists('buyer_pdf_qr_path_for_link')) {
    function buyer_pdf_qr_path_for_link(string $link): string
    {
        if (!buyer_pdf_qr_library_available()) {
            return '';
        }

        $cacheDirectory = buyer_pdf_qr_cache_directory();
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        $qrPath = $cacheDirectory . '/' . sha1($link) . '.png';
        if (!is_file($qrPath)) {
            QRcode::png($link, $qrPath);
        }

        return $qrPath;
    }
}

if (!function_exists('buyer_generate_ticket_pdf')) {
    function buyer_generate_ticket_pdf(mysqli $conn, int $buyerId, bool $writeToDisk = true): array
    {
        global $buyerPdfAppConfig;

        ensureBuyerSchema($conn);
        $layout = ticket_layout_load();

        $buyerStmt = $conn->prepare("SELECT b.id, b.full_name, b.email, b.chapter, b.buyer_type
            FROM buyers b
            WHERE b.id = ?
            LIMIT 1");
        if (!$buyerStmt) {
            throw new RuntimeException('Unable to load buyer PDF details.');
        }

        $buyerStmt->bind_param('i', $buyerId);
        $buyerStmt->execute();
        $buyer = $buyerStmt->get_result()->fetch_assoc();
        $buyerStmt->close();

        $assignedTickets = ticket_fetch_buyer_assigned_tickets($conn, $buyerId);
        if (!$buyer || $assignedTickets === []) {
            throw new RuntimeException('No assigned tickets found for this buyer.');
        }

        $ticketNumbers = [];
        $ticketLinks = [];
        foreach ($assignedTickets as $assignedTicket) {
            $ticketNumber = trim((string) ($assignedTicket['ticket_number'] ?? ''));
            if ($ticketNumber === '') {
                continue;
            }
            $ticketNumbers[] = $ticketNumber;
            $ticketLinks[$ticketNumber] = [
                'verification_link' => trim((string) ($assignedTicket['verification_link'] ?? '')),
                'instruction_link' => trim((string) ($assignedTicket['instruction_link'] ?? '')),
            ];
        }
        if ($ticketNumbers === []) {
            throw new RuntimeException('No assigned tickets found for this buyer.');
        }

        $activationData = ticket_buyer_activation_link_data($conn, $buyerPdfAppConfig, $buyerId);
        $defaultActivationLink = trim((string) ($activationData['activation_link'] ?? ''));
        if ($defaultActivationLink === '') {
            throw new RuntimeException('Unable to resolve activation link for ticket QR generation.');
        }

        $backgroundImage = __DIR__ . '/' . ltrim((string) $layout['background_image'], '/');
        if (!is_file($backgroundImage)) {
            throw new RuntimeException('Ticket background image not found for current layout.');
        }

        $pageWidth = (float) $layout['page_width_mm'];
        $pageHeight = (float) $layout['page_height_mm'];
        $pdf = new FPDF('L', 'mm', [$pageWidth, $pageHeight]);
        $pdf->SetMargins(0, 0, 0);
        $textColor = ticket_layout_color_rgb((string) $layout['text_color_hex']);
        $textValue = ticket_layout_display_text($layout, $ticketNumbers);

        foreach ($ticketNumbers as $ticketNumber) {
            $pdf->AddPage();
            $pdf->Image($backgroundImage, 0, 0, $pageWidth, $pageHeight);

            $ticketActivationLink = trim((string) ($ticketLinks[$ticketNumber]['verification_link'] ?? ''));
            $qrLink = $ticketActivationLink !== '' ? $ticketActivationLink : $defaultActivationLink;
            $qrPath = buyer_pdf_qr_path_for_link($qrLink);
            if (is_file($qrPath)) {
                $pdf->Image($qrPath, (float) $layout['qr_x_mm'], (float) $layout['qr_y_mm'], (float) $layout['qr_size_mm']);
            }

            $pdf->SetFont('Arial', 'B', (float) $layout['text_font_size_pt']);
            $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);

            $displayText = (string) ($layout['text_mode'] === 'ticket_number' ? $ticketNumber : $textValue);
            if ((string) $layout['text_mode'] === 'auto') {
                $displayText = count($ticketNumbers) === 1 ? $ticketNumber : $textValue;
            }

            $x = (float) $layout['text_x_mm'];
            $y = (float) $layout['text_y_mm'];
            if ((string) $layout['text_align'] === 'center') {
                $pdf->SetXY(0, $y - 4);
                $pdf->Cell($pageWidth, 8, $displayText, 0, 0, 'C');
            } elseif ((string) $layout['text_align'] === 'right') {
                $pdf->SetXY(0, $y - 4);
                $pdf->Cell($pageWidth - $x, 8, $displayText, 0, 0, 'R');
            } else {
                $pdf->Text($x, $y, $displayText);
            }
        }

        $pdfBinary = $pdf->Output('S');
        $outputPath = buyer_pdf_output_path($buyerId);
        if ($writeToDisk) {
            file_put_contents($outputPath, $pdfBinary);
        }

        return [
            'path' => $outputPath,
            'filename' => buyer_pdf_filename($buyer, $ticketNumbers),
            'content' => $pdfBinary,
            'ticket_numbers' => $ticketNumbers,
        ];
    }
}
