<?php
include 'db.php';
require_once __DIR__ . '/onsite_purchase_helper.php';
require_once __DIR__ . '/drive_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

onsite_ensure_schema($conn);

$message = '';
$messageType = 'success';

$submitCooldownSeconds = 15;
$duplicateWindowSeconds = 180;

if (!isset($_SESSION['buy_ticket_submission_fingerprints']) || !is_array($_SESSION['buy_ticket_submission_fingerprints'])) {
    $_SESSION['buy_ticket_submission_fingerprints'] = [];
}

if (!isset($_SESSION['buy_ticket_buyer_submit_timestamps']) || !is_array($_SESSION['buy_ticket_buyer_submit_timestamps'])) {
    $_SESSION['buy_ticket_buyer_submit_timestamps'] = [];
}

if (!isset($_SESSION['buy_ticket_form_token']) || !is_string($_SESSION['buy_ticket_form_token']) || $_SESSION['buy_ticket_form_token'] === '') {
    $_SESSION['buy_ticket_form_token'] = bin2hex(random_bytes(32));
}

function buy_ticket_normalize_text(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return trim((string) $normalized);
}

function buy_ticket_submission_fingerprint(array $data): string
{
    $name = buy_ticket_normalize_text((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $contact = preg_replace('/\D+/', '', (string) ($data['contact_number'] ?? ''));
    $chapter = buy_ticket_normalize_text((string) ($data['chapter'] ?? ''));
    $quantity = (int) ($data['ticket_quantity'] ?? 0);

    return hash('sha256', implode('|', [$name, $email, $contact, $chapter, (string) $quantity]));
}

function buy_ticket_buyer_key(array $data): string
{
    $name = buy_ticket_normalize_text((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $contact = preg_replace('/\D+/', '', (string) ($data['contact_number'] ?? ''));

    return hash('sha256', implode('|', [$name, $email, $contact]));
}

function buy_ticket_find_recent_buyer_request(mysqli $conn, array $data, int $windowSeconds): ?array
{
    if ($windowSeconds <= 0) {
        return null;
    }

    $name = buy_ticket_normalize_text((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $contact = preg_replace('/\D+/', '', (string) ($data['contact_number'] ?? ''));
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

    $stmt = $conn->prepare('SELECT id, status, created_at
        FROM ticket_purchase_requests
        WHERE LOWER(TRIM(name)) = ?
          AND LOWER(TRIM(email)) = ?
          AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", ""), "+", "") = ?
          AND created_at >= ?
        ORDER BY id DESC
        LIMIT 1');

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ssss', $name, $email, $contact, $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function buy_ticket_find_recent_duplicate_request(mysqli $conn, array $data, int $windowSeconds): ?array
{
    if ($windowSeconds <= 0) {
        return null;
    }

    $name = buy_ticket_normalize_text((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $contact = preg_replace('/\D+/', '', (string) ($data['contact_number'] ?? ''));
    $chapter = buy_ticket_normalize_text((string) ($data['chapter'] ?? ''));
    $quantity = (int) ($data['ticket_quantity'] ?? 0);
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

    $stmt = $conn->prepare('SELECT id, status, created_at
        FROM ticket_purchase_requests
        WHERE LOWER(TRIM(name)) = ?
          AND LOWER(TRIM(email)) = ?
          AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", ""), "+", "") = ?
          AND LOWER(TRIM(chapter)) = ?
          AND ticket_quantity = ?
          AND created_at >= ?
          AND status IN ("pending", "approved")
        ORDER BY id DESC
        LIMIT 1');

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ssssis', $name, $email, $contact, $chapter, $quantity, $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

$formData = [
    'name' => '',
    'email' => '',
    'contact_number' => '',
    'chapter' => '',
    'ticket_quantity' => '1',
];

$paymentImageUrls = onsite_get_payment_image_urls($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionToken = (string) ($_SESSION['buy_ticket_form_token'] ?? '');
    $postedToken = trim((string) ($_POST['form_token'] ?? ''));

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $message = 'Duplicate or expired form submission detected. Please try again.';
        $messageType = 'danger';
        $_SESSION['buy_ticket_form_token'] = bin2hex(random_bytes(32));
    } else {
        unset($_SESSION['buy_ticket_form_token']);

    $formData['name'] = trim((string) ($_POST['name'] ?? ''));
    $formData['email'] = trim((string) ($_POST['email'] ?? ''));
    $formData['contact_number'] = trim((string) ($_POST['contact_number'] ?? ''));
    $formData['chapter'] = trim((string) ($_POST['chapter'] ?? ''));
    $formData['ticket_quantity'] = trim((string) ($_POST['ticket_quantity'] ?? '1'));

    try {
        $nowTs = time();

        if ($formData['name'] === '' || $formData['email'] === '' || $formData['contact_number'] === '' || $formData['chapter'] === '') {
            throw new RuntimeException('Please complete all required fields.');
        }
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid email address.');
        }

        $quantity = (int) $formData['ticket_quantity'];
        if ($quantity <= 0 || $quantity > 1000) {
            throw new RuntimeException('Ticket quantity must be between 1 and 1000.');
        }

        $buyerData = [
            'name' => $formData['name'],
            'email' => $formData['email'],
            'contact_number' => $formData['contact_number'],
        ];
        $buyerKey = buy_ticket_buyer_key($buyerData);

        foreach ($_SESSION['buy_ticket_buyer_submit_timestamps'] as $storedBuyerKey => $createdTs) {
            if (($nowTs - (int) $createdTs) > $duplicateWindowSeconds) {
                unset($_SESSION['buy_ticket_buyer_submit_timestamps'][$storedBuyerKey]);
            }
        }

        $lastBuyerSubmitTs = (int) ($_SESSION['buy_ticket_buyer_submit_timestamps'][$buyerKey] ?? 0);
        if ($lastBuyerSubmitTs > 0 && ($nowTs - $lastBuyerSubmitTs) < $submitCooldownSeconds) {
            $waitSeconds = $submitCooldownSeconds - ($nowTs - $lastBuyerSubmitTs);
            throw new RuntimeException('Please wait ' . $waitSeconds . ' second(s) before sending another request for the same buyer.');
        }

        $recentBuyerSubmit = buy_ticket_find_recent_buyer_request($conn, $buyerData, $submitCooldownSeconds);
        if (is_array($recentBuyerSubmit)) {
            $recentId = (int) ($recentBuyerSubmit['id'] ?? 0);
            throw new RuntimeException('A recent request for this buyer was already submitted (Reference #' . $recentId . '). Please wait a few seconds and try again.');
        }

        if (!isset($_FILES['proof_of_payment']) || !is_array($_FILES['proof_of_payment'])) {
            throw new RuntimeException('Please upload proof of payment.');
        }

        $proof = $_FILES['proof_of_payment'];
        $proofError = (int) ($proof['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($proofError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Proof of payment upload failed. Please try again.');
        }

        $proofTmp = (string) ($proof['tmp_name'] ?? '');
        if ($proofTmp === '' || !is_uploaded_file($proofTmp)) {
            throw new RuntimeException('Uploaded proof of payment is invalid.');
        }

        $proofName = basename((string) ($proof['name'] ?? 'proof'));
        $proofExt = strtolower(pathinfo($proofName, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'];
        if (!in_array($proofExt, $allowedExt, true)) {
            throw new RuntimeException('Unsupported proof file type. Allowed: ' . implode(', ', $allowedExt) . '.');
        }

        $proofSize = (int) ($proof['size'] ?? 0);
        $maxSize = 10 * 1024 * 1024;
        if ($proofSize <= 0 || $proofSize > $maxSize) {
            throw new RuntimeException('Proof file must be between 1 byte and 10MB.');
        }

        foreach ($_SESSION['buy_ticket_submission_fingerprints'] as $fingerprint => $createdTs) {
            if (($nowTs - (int) $createdTs) > $duplicateWindowSeconds) {
                unset($_SESSION['buy_ticket_submission_fingerprints'][$fingerprint]);
            }
        }

        $submissionFingerprint = buy_ticket_submission_fingerprint([
            'name' => $formData['name'],
            'email' => $formData['email'],
            'contact_number' => $formData['contact_number'],
            'chapter' => $formData['chapter'],
            'ticket_quantity' => $quantity,
        ]);

        if (isset($_SESSION['buy_ticket_submission_fingerprints'][$submissionFingerprint])) {
            throw new RuntimeException('A similar request was just submitted. Please wait a few minutes before trying again.');
        }

        $recentDuplicate = buy_ticket_find_recent_duplicate_request($conn, [
            'name' => $formData['name'],
            'email' => $formData['email'],
            'contact_number' => $formData['contact_number'],
            'chapter' => $formData['chapter'],
            'ticket_quantity' => $quantity,
        ], $duplicateWindowSeconds);

        if (is_array($recentDuplicate)) {
            $duplicateId = (int) ($recentDuplicate['id'] ?? 0);
            throw new RuntimeException('A recent matching request already exists (Reference #' . $duplicateId . '). Please wait for admin verification.');
        }

        $uploadResult = ticket_upload_to_google_drive($proof, $appConfig);
        $proofLink = trim((string) ($uploadResult['web_view_link'] ?? ''));
        $driveFileId = trim((string) ($uploadResult['file_id'] ?? ''));

        if ($proofLink === '') {
            throw new RuntimeException('Google Drive upload succeeded but no file link was returned.');
        }

        $requestId = onsite_create_purchase_request($conn, [
            'name' => $formData['name'],
            'email' => $formData['email'],
            'contact_number' => $formData['contact_number'],
            'chapter' => $formData['chapter'],
            'ticket_quantity' => $quantity,
            'proof_of_payment_url' => $proofLink,
            'drive_file_id' => $driveFileId,
        ]);

        $message = 'Purchase request submitted successfully. Reference ID: #' . $requestId . '. Our team will verify your payment and email your ticket activation details after approval.';
        $messageType = 'success';

        $_SESSION['buy_ticket_buyer_submit_timestamps'][$buyerKey] = $nowTs;
        $_SESSION['buy_ticket_submission_fingerprints'][$submissionFingerprint] = $nowTs;

        $formData = [
            'name' => '',
            'email' => '',
            'contact_number' => '',
            'chapter' => '',
            'ticket_quantity' => '1',
        ];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'danger';
    }

    $_SESSION['buy_ticket_form_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onsite Ticket Purchase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #172033;
            --paper: #f5f7fb;
            --accent: #0f766e;
            --accent-soft: #14b8a61f;
            --line: #d7dee9;
            --danger: #b91c1c;
            --success: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 12%, #dbeafe 0%, transparent 38%),
                radial-gradient(circle at 88% 10%, #ccfbf1 0%, transparent 33%),
                linear-gradient(150deg, #f8fafc 0%, #eef2ff 52%, #ecfeff 100%);
            padding: 28px 14px;
        }
        .wrap {
            max-width: 1050px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }
        .panel {
            background: rgba(255, 255, 255, 0.93);
            border: 1px solid var(--line);
            border-radius: 20px;
            backdrop-filter: blur(3px);
            box-shadow: 0 16px 38px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .hero {
            padding: 30px 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #99f6e4;
            background: #ecfeff;
            color: #0f766e;
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 999px;
            width: fit-content;
            letter-spacing: .03em;
            text-transform: uppercase;
            font-weight: 700;
        }
        h1 {
            margin: 0;
            font-family: 'Sora', sans-serif;
            line-height: 1.18;
            font-size: clamp(26px, 4vw, 38px);
        }
        .hero p {
            margin: 0;
            color: #475569;
            line-height: 1.6;
            max-width: 42ch;
        }
        .payment {
            margin-top: 8px;
            background: var(--accent-soft);
            border: 1px dashed #2dd4bf;
            border-radius: 14px;
            padding: 16px;
        }
        .payment h2 {
            margin: 0 0 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #0f766e;
            font-family: 'Sora', sans-serif;
        }
        .payment img {
            display: block;
            width: 100%;
            max-height: 360px;
            object-fit: contain;
            border-radius: 10px;
            border: 1px solid #99f6e4;
            background: #ffffff;
        }
        .payment-gallery {
            display: grid;
            gap: 10px;
        }
        .form-card {
            padding: 24px;
        }
        .form-card h2 {
            margin: 2px 0 16px;
            font-family: 'Sora', sans-serif;
            font-size: 22px;
        }
        .alert {
            margin-bottom: 14px;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            line-height: 1.4;
        }
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: var(--success);
        }
        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--danger);
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 12px;
        }
        .field.full {
            grid-column: 1 / -1;
        }
        label {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        input, textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            background: #fff;
            border-radius: 12px;
            padding: 11px 12px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            color: #0f172a;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        input:focus, textarea:focus {
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.18);
            outline: none;
        }
        .hint {
            margin-top: 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
        }
        button {
            border: none;
            background: linear-gradient(120deg, #0f766e, #0ea5e9);
            color: #fff;
            border-radius: 12px;
            padding: 12px 18px;
            font-family: 'Sora', sans-serif;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .02em;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(14, 116, 144, 0.24);
        }
        .foot {
            margin-top: 12px;
            font-size: 12px;
            color: #64748b;
        }
        @media (max-width: 900px) {
            .wrap { grid-template-columns: 1fr; }
            .grid { grid-template-columns: 1fr; }
            .hero { padding: 24px 20px; }
            .form-card { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="panel hero">
            <span class="tag">Onsite Selling</span>
            <h1>Reserve your raffle tickets and upload proof of payment</h1>
            <p><strong>Ticket Price: P100.00</strong></p>
            <p>Submit your purchase request below. Our admin or secretariat team will verify your payment, assign your requested tickets, and send your activation email.</p>
            <div class="payment">
                <h2>Payment Options</h2>
                <?php if ($paymentImageUrls !== []): ?>
                    <div class="payment-gallery">
                        <?php foreach ($paymentImageUrls as $idx => $paymentImageUrl): ?>
                            <img src="<?php echo htmlspecialchars((string) $paymentImageUrl, ENT_QUOTES); ?>" alt="Payment Option <?php echo (int) ($idx + 1); ?>">
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hint" style="margin:0;">Payment options image is not yet available. Please contact support before submitting your purchase request.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel form-card">
            <h2>Purchase Request Form</h2>
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="purchaseRequestForm">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars((string) ($_SESSION['buy_ticket_form_token'] ?? ''), ENT_QUOTES); ?>">
                <div class="grid">
                    <div class="field full">
                        <label for="name">Full Name</label>
                        <input type="text" name="name" id="name" required maxlength="255" value="<?php echo htmlspecialchars($formData['name'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input type="email" name="email" id="email" required maxlength="255" value="<?php echo htmlspecialchars($formData['email'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" name="contact_number" id="contact_number" required maxlength="50" value="<?php echo htmlspecialchars($formData['contact_number'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label for="chapter">Chapter</label>
                        <input type="text" name="chapter" id="chapter" required maxlength="100" value="<?php echo htmlspecialchars($formData['chapter'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label for="ticket_quantity">Ticket Quantity</label>
                        <input type="number" name="ticket_quantity" id="ticket_quantity" min="1" max="1000" required value="<?php echo htmlspecialchars($formData['ticket_quantity'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field full">
                        <label for="proof_of_payment">Proof of Payment</label>
                        <input type="file" name="proof_of_payment" id="proof_of_payment" required accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx">
                        <p class="hint">Accepted files: JPG, PNG, WEBP, PDF, DOC, DOCX. Maximum size: 10MB.</p>
                    </div>
                </div>
                <button type="submit" id="submitPurchaseRequestBtn">Submit Purchase Request</button>
                <p class="foot">By submitting, you confirm the payment details and contact information are accurate.</p>
            </form>
        </section>
    </div>

    <script>
    (function () {
        const form = document.getElementById('purchaseRequestForm');
        const submitBtn = document.getElementById('submitPurchaseRequestBtn');
        if (!form || !submitBtn) {
            return;
        }

        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        });
    })();
    </script>
</body>
</html>
