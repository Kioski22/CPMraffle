<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['chapter']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';
require_once __DIR__ . '/chapter_portal_helper.php';

ensureBuyerSchema($conn);

$chapterId = auth_user_id();
try {
    $chapterUser = chapter_portal_require_user($conn, $chapterId);
} catch (Throwable $exception) {
    http_response_code(403);
    exit($exception->getMessage());
}

if (!$chapterUser) {
    http_response_code(403);
    exit('Chapter account not found.');
}

$chapterName = trim((string) ($chapterUser['chapter_name'] ?? $chapterUser['name'] ?? $chapterUser['username'] ?? 'Chapter'));
$flashMessage = '';
$flashType = 'success';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    try {
        if (!is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            throw new RuntimeException('Please upload a CSV file.');
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to read uploaded CSV.');
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new RuntimeException('The CSV file is empty.');
        }

        $map = [];
        foreach ($header as $index => $column) {
            $map[strtolower(trim((string) $column))] = $index;
        }

        $required = ['full_name', 'email', 'contact'];
        foreach ($required as $column) {
            if (!array_key_exists($column, $map)) {
                fclose($handle);
                throw new RuntimeException('Missing required CSV column: ' . $column);
            }
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $fullName = trim((string) ($row[$map['full_name']] ?? ''));
            $email = trim((string) ($row[$map['email']] ?? ''));
            $contact = trim((string) ($row[$map['contact']] ?? ''));
            $buyerType = trim((string) ($row[$map['buyer_type']] ?? 'individual'));
            if ($buyerType === '') {
                $buyerType = 'individual';
            }

            if ($fullName === '' || $email === '' || $contact === '') {
                $results[] = 'Skipped a row with missing data.';
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = $email . ': invalid email address.';
                continue;
            }

            $rows[] = [
                'full_name' => $fullName,
                'email' => $email,
                'contact' => $contact,
                'buyer_type' => $buyerType,
            ];
        }

        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('No valid rows found in uploaded CSV.');
        }

        $tickets = chapter_portal_available_tickets($conn, $chapterId, count($rows));
        if (count($tickets) < count($rows)) {
            throw new RuntimeException('Not enough pending tickets in chapter allocation. Needed ' . count($rows) . ', available ' . count($tickets) . '.');
        }

        foreach ($rows as $index => $csvRow) {
            $ticketNumber = (string) ($tickets[$index] ?? '');
            if ($ticketNumber === '') {
                $results[] = 'Skipping a row due to missing available ticket.';
                continue;
            }

            $conn->begin_transaction();
            try {
                $status = 'pending';
                $insertBuyer = $conn->prepare('INSERT INTO buyers (buyer_type, full_name, email, contact, chapter, status) VALUES (?, ?, ?, ?, ?, ?)');
                if (!$insertBuyer) {
                    throw new RuntimeException('Unable to create buyer.');
                }
                $insertBuyer->bind_param('ssssss', $csvRow['buyer_type'], $csvRow['full_name'], $csvRow['email'], $csvRow['contact'], $chapterName, $status);
                $insertBuyer->execute();
                $buyerId = (int) $insertBuyer->insert_id;
                $insertBuyer->close();

                $assignmentMode = 'chapter';
                $adminId = $chapterId;
                $assignmentStatus = 'assigned';
                $insertAssignment = $conn->prepare('INSERT INTO ticket_assignments (buyer_id, ticket_number, assignment_mode, assigned_by_admin_id, assignment_status) VALUES (?, ?, ?, ?, ?)');
                if (!$insertAssignment) {
                    throw new RuntimeException('Unable to store ticket assignment.');
                }
                $insertAssignment->bind_param('issis', $buyerId, $ticketNumber, $assignmentMode, $adminId, $assignmentStatus);
                $insertAssignment->execute();
                $insertAssignment->close();

                $conn->commit();

                try {
                    send_buyer_assignment_email($conn, $appConfig, [
                        'id' => $buyerId,
                        'full_name' => $csvRow['full_name'],
                        'email' => $csvRow['email'],
                        'chapter' => $chapterName,
                        'buyer_type' => $csvRow['buyer_type'],
                    ], [$ticketNumber]);
                    $statusAfterEmail = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
                    $markSent = $conn->prepare('UPDATE buyers SET status = ?, email_sent_at = NOW(), updated_at = NOW() WHERE id = ?');
                    if ($markSent) {
                        $markSent->bind_param('si', $statusAfterEmail, $buyerId);
                        $markSent->execute();
                        $markSent->close();
                    }
                    $results[] = $ticketNumber . ': assigned and emailed.';
                } catch (Throwable $mailException) {
                    $results[] = $ticketNumber . ': assigned, but email failed - ' . $mailException->getMessage();
                }
            } catch (Throwable $rowException) {
                @$conn->rollback();
                $results[] = $ticketNumber . ': ' . $rowException->getMessage();
            }
        }

        $flashMessage = 'Bulk upload processed.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php chapter_portal_render_head('Chapter Bulk Upload'); ?>
<body>
<div class="dashboard-container">
    <?php chapter_portal_render_sidebar('/CPMraffle/chapter_bulk_upload.php'); ?>
<div class="chapter-shell">
    <section class="chapter-hero">
        <div class="chapter-eyebrow"><i class="fas fa-file-upload"></i> Bulk Buyer Upload</div>
        <h1>Upload a prepared CSV file and assign multiple buyers in one guided step.</h1>
        <p class="mb-0">Use this page when you already have buyer names, emails, and contact numbers in a spreadsheet. The system will assign tickets in order from your chapter's available pool.</p>
        <div class="chapter-meta">
            <div class="chapter-meta-chip"><i class="fas fa-building"></i> Chapter <strong><?php echo htmlspecialchars($chapterName, ENT_QUOTES); ?></strong></div>
        </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="chapter-panel h-100">
                <div class="chapter-upload-box h-100">
                    <div class="chapter-upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                    <h2 class="chapter-section-title mb-2">Select Your CSV File</h2>
                    <p class="chapter-section-intro">Choose a CSV with the required column headings so the system can assign tickets automatically and send activation emails.</p>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3 text-start">
                            <label class="chapter-form-label" for="csv_file">CSV File</label>
                            <input id="csv_file" type="file" name="csv_file" class="form-control form-control-lg chapter-input w-100" accept=".csv,text/csv" required>
                            <div class="chapter-form-help">Required columns: full_name, email, contact. Optional column: buyer_type.</div>
                        </div>
                        <div class="d-grid gap-3 mt-4">
                            <button type="submit" class="chapter-primary-button w-100"><i class="fas fa-upload"></i> Upload and Assign</button>
                            <a href="/CPMraffle/chapter_dashboard.php" class="chapter-secondary-button w-100"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="chapter-panel h-100">
                <h2 class="chapter-section-title mb-2">How To Prepare The File</h2>
                <p class="chapter-section-intro">Follow these steps exactly to avoid upload errors.</p>
                <div class="chapter-steps">
                    <div class="chapter-step">
                        <div class="chapter-step-number">1</div>
                        <div><strong>Use the correct column names.</strong><br>Start the first row with full_name, email, and contact. You may also add buyer_type.</div>
                    </div>
                    <div class="chapter-step">
                        <div class="chapter-step-number">2</div>
                        <div><strong>One buyer per row.</strong><br>Each buyer must have a full name, a valid email address, and a contact number before upload.</div>
                    </div>
                    <div class="chapter-step">
                        <div class="chapter-step-number">3</div>
                        <div><strong>Save as CSV.</strong><br>When your spreadsheet is ready, export or save the file as a .csv file and upload it here.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($results !== []): ?>
        <div class="chapter-panel">
            <h2 class="chapter-section-title mb-2">Upload Results</h2>
            <p class="chapter-section-intro">Review each line below to see which buyers were assigned successfully and which rows need attention.</p>
            <div class="chapter-results">
                <ul class="mb-0">
                    <?php foreach ($results as $line): ?>
                        <li><?php echo htmlspecialchars($line, ENT_QUOTES); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>
