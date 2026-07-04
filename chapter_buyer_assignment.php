<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['chapter']);

include __DIR__ . '/db.php';
require_once __DIR__ . '/buyer_schema.php';
require_once __DIR__ . '/email_composer.php';
require_once __DIR__ . '/buyer_assignment_mailer.php';
require_once __DIR__ . '/chapter_portal_helper.php';

ensureBuyerSchema($conn);

function chapter_assignment_bind_params(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $bindValues = [];
    $bindValues[] = $types;
    foreach ($values as $index => $_value) {
        $bindValues[] = &$values[$index];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$chapterId = auth_user_id();
try {
    $chapterUser = chapter_portal_require_user($conn, $chapterId);
} catch (Throwable $exception) {
    http_response_code(403);
    exit($exception->getMessage());
}

$chapterName = chapter_portal_chapter_name($chapterUser);
$flashMessage = '';
$flashType = 'success';
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$contact = trim((string) ($_POST['contact'] ?? ''));
$buyerType = trim((string) ($_POST['buyer_type'] ?? 'individual'));
$allowedBuyerTypes = ['individual', 'chapter'];
$selectedTickets = array_values(array_unique(array_filter(array_map('trim', (array) ($_POST['selected_tickets'] ?? [])), static function ($ticket): bool {
    return $ticket !== '';
})));

if (!in_array($buyerType, $allowedBuyerTypes, true)) {
    $buyerType = 'individual';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($fullName === '' || $email === '' || $contact === '') {
            throw new RuntimeException('Full name, email, and contact are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if ($selectedTickets === []) {
            throw new RuntimeException('Please select at least one ticket from the grid.');
        }

        $conn->begin_transaction();

        $placeholders = implode(',', array_fill(0, count($selectedTickets), '?'));
        $lockSql = 'SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.assigned_chapter_id = ?
              AND LOWER(COALESCE(t.status, "")) = "pending"
              AND ta.ticket_number IS NULL
              AND t.ticket_number IN (' . $placeholders . ')
            FOR UPDATE';

        $lockStmt = $conn->prepare($lockSql);
        if (!$lockStmt) {
            throw new RuntimeException('Unable to validate selected tickets.');
        }

        $lockTypes = 'i' . str_repeat('s', count($selectedTickets));
        $lockValues = array_merge([$chapterId], $selectedTickets);
        if (!chapter_assignment_bind_params($lockStmt, $lockTypes, $lockValues)) {
            $lockStmt->close();
            throw new RuntimeException('Unable to bind selected tickets.');
        }

        $lockStmt->execute();
        $lockResult = $lockStmt->get_result();
        $availableLockedTickets = [];
        while ($lockResult && ($row = $lockResult->fetch_assoc())) {
            $availableLockedTickets[] = (string) ($row['ticket_number'] ?? '');
        }
        $lockStmt->close();

        sort($availableLockedTickets);
        $requestedTickets = $selectedTickets;
        sort($requestedTickets);
        if ($availableLockedTickets !== $requestedTickets) {
            throw new RuntimeException('One or more selected tickets are no longer available. Please refresh and select again.');
        }

        $status = 'pending';
        $insertBuyer = $conn->prepare('INSERT INTO buyers (buyer_type, full_name, email, contact, chapter, status) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$insertBuyer) {
            throw new RuntimeException('Unable to create buyer record.');
        }
        $insertBuyer->bind_param('ssssss', $buyerType, $fullName, $email, $contact, $chapterName, $status);
        $insertBuyer->execute();
        $buyerId = (int) $insertBuyer->insert_id;
        $insertBuyer->close();

        $assignmentMode = 'chapter';
        $adminId = $chapterId;
        $assignmentStatus = 'assigned';
        $insertAssignment = $conn->prepare('INSERT INTO ticket_assignments (buyer_id, ticket_number, assignment_mode, assigned_by_admin_id, assignment_status) VALUES (?, ?, ?, ?, ?)');
        if (!$insertAssignment) {
            throw new RuntimeException('Unable to store ticket assignments.');
        }

        foreach ($selectedTickets as $ticketNumber) {
            $insertAssignment->bind_param('issis', $buyerId, $ticketNumber, $assignmentMode, $adminId, $assignmentStatus);
            $insertAssignment->execute();
        }
        $insertAssignment->close();

        $conn->commit();

        $ticketCount = count($selectedTickets);
        $flashMessage = 'Assigned ' . $ticketCount . ' ticket' . ($ticketCount === 1 ? '' : 's') . ' to ' . $fullName . ' and sent the activation email.';
        $flashType = 'success';

        try {
            send_buyer_assignment_email($conn, $appConfig, [
                'id' => $buyerId,
                'full_name' => $fullName,
                'email' => $email,
                'chapter' => $chapterName,
                'buyer_type' => $buyerType,
            ], $selectedTickets);

            $statusAfterEmail = buyerDetermineStatus(buyerFetchActivationCounts($conn, $buyerId), true);
            $markSent = $conn->prepare('UPDATE buyers SET status = ?, email_sent_at = NOW(), updated_at = NOW() WHERE id = ?');
            if ($markSent) {
                $markSent->bind_param('si', $statusAfterEmail, $buyerId);
                $markSent->execute();
                $markSent->close();
            }
        } catch (Throwable $mailException) {
            $flashMessage = 'Assigned ' . count($selectedTickets) . ' ticket(s) to ' . $fullName . ', but email failed: ' . $mailException->getMessage();
            $flashType = 'warning';
        }

        $fullName = '';
        $email = '';
        $contact = '';
        $selectedTickets = [];
    } catch (Throwable $exception) {
        @$conn->rollback();
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$availableTickets = chapter_portal_available_tickets($conn, $chapterId, 0);
$availableTicketCount = count($availableTickets);
?>
<!DOCTYPE html>
<html lang="en">
<?php chapter_portal_render_head('Chapter Buyer Assignment'); ?>
<body>
<div class="dashboard-container">
    <?php chapter_portal_render_sidebar('/CPMraffle/chapter_buyer_assignment.php'); ?>
    <div class="chapter-shell">
        <section class="chapter-hero">
            <div class="chapter-eyebrow"><i class="fas fa-user-plus"></i> Assign Buyer With Ticket Grid</div>
            <h1>Select one or more specific tickets, then assign them to a single buyer.</h1>
            <p class="mb-0">Use the ticket grid below like a calendar: tap the boxes you want, then submit once with buyer details.</p>
            <div class="chapter-meta">
                <div class="chapter-meta-chip"><i class="fas fa-building"></i> Chapter <strong><?php echo htmlspecialchars($chapterName, ENT_QUOTES); ?></strong></div>
                <div class="chapter-meta-chip"><i class="fas fa-ticket"></i> Available tickets <strong><?php echo htmlspecialchars((string) $availableTicketCount, ENT_QUOTES); ?></strong></div>
            </div>
        </section>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <div class="chapter-panel mb-4">
            <div class="mb-4">
                <h2 class="chapter-section-title mb-1">Buyer Information</h2>
                <p class="chapter-section-intro mb-0">Complete buyer details and choose tickets from the grid. You can assign multiple tickets in one submission.</p>
            </div>
            <form method="POST" id="chapter-assignment-form">
                <div class="mb-4">
                    <label class="chapter-form-label">Ticket Selection Grid</label>
                    <div class="chapter-form-help mb-2">Tap each ticket you want to assign. Selected tickets are highlighted.</div>

                    <?php if ($availableTickets === []): ?>
                        <div class="alert alert-warning mb-0">No unassigned tickets are currently available in your chapter allocation.</div>
                    <?php else: ?>
                        <div class="ticket-grid-wrap">
                            <div class="ticket-grid" role="group" aria-label="Available tickets">
                                <?php foreach ($availableTickets as $ticketNumber): ?>
                                    <?php $checked = in_array($ticketNumber, $selectedTickets, true); ?>
                                    <label class="ticket-choice" for="ticket-<?php echo htmlspecialchars($ticketNumber, ENT_QUOTES); ?>">
                                        <input
                                            id="ticket-<?php echo htmlspecialchars($ticketNumber, ENT_QUOTES); ?>"
                                            type="checkbox"
                                            class="ticket-choice-input"
                                            name="selected_tickets[]"
                                            value="<?php echo htmlspecialchars($ticketNumber, ENT_QUOTES); ?>"
                                            <?php echo $checked ? 'checked' : ''; ?>
                                        >
                                        <span class="ticket-choice-box">
                                            <i class="fas fa-check ticket-choice-check" aria-hidden="true"></i>
                                            <span class="ticket-choice-number" title="<?php echo htmlspecialchars($ticketNumber, ENT_QUOTES); ?>"><?php echo htmlspecialchars($ticketNumber, ENT_QUOTES); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="chapter-form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control form-control-lg chapter-input w-100" maxlength="120" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>" required>
                        <div class="chapter-form-help">Enter the buyer's complete name so the email and registration record are correct.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="chapter-form-label">Email</label>
                        <input type="email" name="email" class="form-control form-control-lg chapter-input w-100" maxlength="150" value="<?php echo htmlspecialchars($email, ENT_QUOTES); ?>" required>
                        <div class="chapter-form-help">Use the buyer's working email address. The activation message will be sent here.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="chapter-form-label">Contact</label>
                        <input type="text" name="contact" class="form-control form-control-lg chapter-input w-100" maxlength="30" value="<?php echo htmlspecialchars($contact, ENT_QUOTES); ?>" required>
                        <div class="chapter-form-help">Add a phone number or the best contact number for follow-up if needed.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="chapter-form-label">Buyer Type</label>
                        <select name="buyer_type" class="form-select form-select-lg chapter-input w-100">
                            <option value="individual" <?php echo $buyerType === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="chapter" <?php echo $buyerType === 'chapter' ? 'selected' : ''; ?>>Chapter</option>
                        </select>
                        <div class="chapter-form-help">Choose the category that best matches how this buyer was registered.</div>
                    </div>
                </div>

                <div class="chapter-form-actions ticket-submit-wrap mt-4 d-grid gap-3">
                    <button id="assign-submit" type="submit" class="chapter-primary-button w-100" <?php echo $availableTickets === [] ? 'disabled' : ''; ?>>
                        <i class="fas fa-check"></i>
                        <span id="assign-submit-label">Assign 0 Tickets</span>
                    </button>
                    <a href="/CPMraffle/chapter_dashboard.php" class="chapter-secondary-button w-100"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .ticket-grid-wrap {
        border: 1px solid #d4dfec;
        border-radius: 18px;
        background: #f8fbff;
        padding: 12px;
    }

    .ticket-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 10px;
        max-height: 360px;
        overflow-y: auto;
        padding-right: 4px;
    }

    .ticket-choice { cursor: pointer; margin: 0; }

    .ticket-choice-input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .ticket-choice-box {
        position: relative;
        min-height: 62px;
        border: 2px solid #c6d4e4;
        border-radius: 14px;
        background: linear-gradient(180deg, #ffffff 0%, #f5f9ff 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px 10px 8px;
        font-weight: 700;
        color: #17325c;
        transition: all 0.15s ease;
        text-align: center;
        overflow: hidden;
    }

    .ticket-choice-number {
        display: block;
        width: 100%;
        max-width: 100%;
        padding-right: 16px;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-all;
        line-height: 1.2;
        font-size: 0.8rem;
    }

    .ticket-choice-check {
        position: absolute;
        top: 6px;
        right: 6px;
        font-size: 0.72rem;
        opacity: 0;
        transform: scale(0.7);
        flex: 0 0 auto;
        transition: all 0.15s ease;
    }

    .ticket-choice-input:checked + .ticket-choice-box {
        background: #17325c;
        color: #fff;
        border-color: #17325c;
        box-shadow: 0 10px 18px rgba(23, 50, 92, 0.24);
    }

    .ticket-choice-input:checked + .ticket-choice-box .ticket-choice-check {
        opacity: 1;
        transform: scale(1);
    }

    .ticket-choice-input:focus-visible + .ticket-choice-box {
        outline: 3px solid rgba(46, 95, 155, 0.45);
        outline-offset: 2px;
    }

    .ticket-choice:hover .ticket-choice-box {
        border-color: #9fb7d4;
        transform: translateY(-1px);
        box-shadow: 0 6px 12px rgba(23, 50, 92, 0.12);
    }

    .ticket-submit-wrap {
        position: sticky;
        bottom: 8px;
    }

    @media (max-width: 575.98px) {
        .ticket-grid {
            grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
            max-height: 280px;
        }

        .ticket-choice-box {
            min-height: 52px;
            padding: 7px 8px;
        }

        .ticket-choice-number {
            font-size: 0.74rem;
        }

        .ticket-submit-wrap {
            bottom: 0;
        }
    }
</style>

<script>
    (function () {
        var checkboxes = document.querySelectorAll('.ticket-choice-input');
        var label = document.getElementById('assign-submit-label');
        var submitButton = document.getElementById('assign-submit');
        if (!label || !submitButton) {
            return;
        }

        function updateButtonText() {
            var selectedCount = 0;
            checkboxes.forEach(function (input) {
                if (input.checked) {
                    selectedCount += 1;
                }
            });

            label.textContent = 'Assign ' + selectedCount + ' Ticket' + (selectedCount === 1 ? '' : 's');
            submitButton.disabled = selectedCount === 0;
        }

        checkboxes.forEach(function (input) {
            input.addEventListener('change', updateButtonText);
        });

        updateButtonText();
    })();
</script>
</body>
</html>
