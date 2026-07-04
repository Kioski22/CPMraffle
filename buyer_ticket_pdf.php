<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin', 'secretariat']);

require 'db.php';
require_once __DIR__ . '/buyer_pdf_generator.php';

$buyerId = (int) ($_GET['buyer_id'] ?? 0);
$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if ($buyerId <= 0) {
    http_response_code(400);
    echo 'Invalid buyer selected.';
    exit();
}

try {
    $cachedPath = buyer_pdf_output_path($buyerId);
    if ($refresh || !is_file($cachedPath)) {
        $pdfResult = buyer_generate_ticket_pdf($conn, $buyerId, true);
    } else {
        $pdfResult = buyer_generate_ticket_pdf($conn, $buyerId, false);
        $pdfResult['path'] = $cachedPath;
    }

    $filePath = $pdfResult['path'];
    if (!is_file($filePath)) {
        throw new RuntimeException('Buyer PDF could not be generated.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename((string) $pdfResult['filename']) . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit();
} catch (Throwable $exception) {
    http_response_code(500);
    echo $exception->getMessage();
}
