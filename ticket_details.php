<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/return_functions.php';

requireLogin();

$user = currentUser();

if (!$user) {
    header('Location: index.php');
    exit;
}

$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ticketId || $ticketId < 1) {
    http_response_code(400);
    exit('Invalid ticket ID.');
}

$pageTitle = 'Trip Ticket Details';
$error = '';
$success = $_SESSION['ticket_success'] ?? '';
unset($_SESSION['ticket_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';
    $returnDate = $_POST['return_date'] ?? '';
    $remarks = $_POST['remarks'] ?? '';

    if (
        !is_string($action) ||
        !is_string($returnDate) ||
        !is_string($remarks)
    ) {
        $error = 'Invalid form data.';
    } else {
        try {
            $expectedReturnId = null;
            if ($action !== 'record') {
                $expectedReturnId = filter_var($_POST['return_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($expectedReturnId === false || $expectedReturnId === null) {
                    throw new InvalidArgumentException('Refresh the ticket before changing a return.');
                }
            }
            saveReturnAction(
                (int) $ticketId,
                (int) $user['id'],
                $action,
                trim($returnDate),
                trim($remarks),
                $expectedReturnId
            );

            $messages = [
                'record' => 'Physical ticket return recorded successfully.',
                'correct' => 'Return information corrected successfully.',
                'reverse' => 'Recorded return reversed successfully.'
            ];

            $_SESSION['ticket_success'] =
                $messages[$action] ?? 'Changes saved.';

            header(
                'Location: ticket_details.php?id=' . $ticketId,
                true,
                303
            );
            exit;

        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();

        } catch (Throwable $e) {
            error_log('Return action failed: ' . $e->getMessage());
            $error = 'Unable to save changes. Please try again.';
        }
    }
}

// Retrieve the issued ticket and its active recorded return.
$stmt = db()->prepare(
    "SELECT
        t.*,
        v.plate_number,
        d.full_name AS driver_name,
        creator.full_name AS created_by_name,
        updater.full_name AS updated_by_name,
        r.id AS return_id,
        r.return_date,
        r.remarks AS return_remarks,
        r.recorded_at,
        recorder.full_name AS return_recorded_by
    FROM trip_tickets t
    JOIN vehicles v ON v.id = t.vehicle_id
    JOIN drivers d ON d.id = t.driver_id
    JOIN users creator ON creator.id = t.created_by
    LEFT JOIN users updater ON updater.id = t.updated_by
    LEFT JOIN ticket_returns r
        ON r.trip_ticket_id = t.id
        AND r.reversed_at IS NULL
    LEFT JOIN users recorder ON recorder.id = r.recorded_by
    WHERE t.id = ?
    LIMIT 1"
);

$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    http_response_code(404);
    exit('Trip ticket not found.');
}

$isReturned = $ticket['return_id'] !== null;

// Retrieve the audit history for this specific ticket.
$stmt = db()->prepare(
    "SELECT
        a.action,
        a.old_values,
        a.new_values,
        a.created_at,
        u.full_name AS staff_name
    FROM audit_logs a
    JOIN users u ON u.id = a.user_id
    WHERE a.trip_ticket_id = ?
    ORDER BY a.id DESC"
);
$stmt->execute([$ticketId]);
$auditLogs = $stmt->fetchAll();

require_once __DIR__ . '/../app/audit.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= e($ticket['ticket_number']) ?> | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>
</head>
<body>

<div class="app-layout">

    <?php require __DIR__ . '/../app/partials/sidebar.php'; ?>

    <div class="tl-content">

        <?php require __DIR__ . '/../app/partials/header.php'; ?>

        <main class="tl-dashboard-body">

            <div class="tl-detail-back">
                <a href="records.php">
                    &larr; Back to Trip Ticket Records
                </a>
            </div>

            <section class="tl-welcome">
                <div class="tl-detail-heading">
                    <div>
                        <h2>Trip Ticket Details</h2>
                        <p>
                            Ticket Number:
                            <strong><?= e($ticket['ticket_number']) ?></strong>
                        </p>
                    </div>

                    <span class="tl-status <?= $isReturned
                        ? 'tl-status-returned'
                        : 'tl-status-outstanding' ?>">
                        <?= $isReturned
                            ? 'Returned'
                            : 'Not Yet Recorded as Returned' ?>
                    </span>
                </div>
            </section>

            <?php if ($success !== ''): ?>
                <div class="tl-issuance-alert tl-alert-success"
                     role="status"
                     data-toast-message="<?= e($success) ?>"
                     data-toast-type="success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="tl-issuance-alert tl-alert-error"
                     role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="app-card">
                <div class="tl-detail-section-heading">
                    <h2 class="tl-section-heading">
                        Issuance Information
                    </h2>

                    <a
                        href="correct_ticket.php?id=<?= (int) $ticketId ?>"
                        class="app-button app-button-secondary"
                    >
                        Correct Issuance
                    </a>
                </div>

                <div class="tl-detail-grid">
                    <div class="tl-detail-item">
                        <small>Ticket Number</small>
                        <strong><?= e($ticket['ticket_number']) ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Transaction Date</small>
                        <strong><?= e($ticket['transaction_date']) ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Vehicle Plate Number</small>
                        <strong><?= e($ticket['plate_number']) ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Driver's Name</small>
                        <strong><?= e($ticket['driver_name']) ?></strong>
                    </div>

                    <div class="tl-detail-item tl-detail-wide">
                        <small>Issuance Remarks</small>
                        <strong><?= e($ticket['remarks'] ?: '—') ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Created By</small>
                        <strong><?= e($ticket['created_by_name']) ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Created At</small>
                        <strong><?= e($ticket['created_at']) ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Last Updated By</small>
                        <strong><?= e($ticket['updated_by_name'] ?: '—') ?></strong>
                    </div>

                    <div class="tl-detail-item">
                        <small>Last Updated At</small>
                        <strong><?= e($ticket['updated_at']) ?></strong>
                    </div>
                </div>
            </section>

            <section class="app-card">
                <h2 class="tl-section-heading">
                    Physical Ticket Return
                </h2>

                <?php if (!$isReturned): ?>

                    <p class="tl-detail-help">
                        No physical return has been recorded for this ticket.
                        This does not mean the document is lost or overdue.
                    </p>

                    <form method="POST"
                          class="tl-detail-form"
                          data-confirm="Confirm that the physical ticket has been returned?">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e(csrfToken()) ?>">

                        <input type="hidden"
                               name="action"
                               value="record">

                        <div class="tl-issuance-field">
                            <label for="return_date">Return Date *</label>
                            <input
                                type="date"
                                id="return_date"
                                name="return_date"
                                min="<?= e($ticket['transaction_date']) ?>"
                                value="<?= e(date('Y-m-d')) ?>"
                                required
                            >
                        </div>

                        <div class="tl-issuance-field">
                            <label for="record_remarks">
                                Return Remarks (Optional)
                            </label>
                            <textarea
                                id="record_remarks"
                                name="remarks"
                                maxlength="5000"
                                rows="3"
                                placeholder="Additional details about the physical return"
                            ></textarea>
                        </div>

                        <button type="submit"
                                class="app-button app-button-primary">
                            Record Physical Return
                        </button>
                    </form>

                <?php else: ?>

                    <div class="tl-detail-grid">
                        <div class="tl-detail-item">
                            <small>Return Date</small>
                            <strong><?= e($ticket['return_date']) ?></strong>
                        </div>

                        <div class="tl-detail-item">
                            <small>Return Recorded By</small>
                            <strong><?= e($ticket['return_recorded_by']) ?></strong>
                        </div>

                        <div class="tl-detail-item">
                            <small>Return Recorded At</small>
                            <strong><?= e($ticket['recorded_at']) ?></strong>
                        </div>

                        <div class="tl-detail-item">
                            <small>Return Remarks</small>
                            <strong><?= e($ticket['return_remarks'] ?: '—') ?></strong>
                        </div>
                    </div>

                    <hr class="tl-detail-divider">

                    <h3 class="tl-detail-subheading">
                        Correct Return Information
                    </h3>

                    <p class="tl-detail-help">
                        Changes will be recorded in the audit history,
                        including previous and updated values.
                    </p>

                    <form method="POST"
                          class="tl-detail-form"
                          data-confirm="Confirm this correction? Previous and updated values will be logged.">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e(csrfToken()) ?>">

                        <input type="hidden"
                               name="action"
                               value="correct">
                        <input type="hidden" name="return_id" value="<?= (int) $ticket['return_id'] ?>">

                        <div class="tl-issuance-field">
                            <label for="correct_return_date">
                                Correct Return Date *
                            </label>
                            <input
                                type="date"
                                id="correct_return_date"
                                name="return_date"
                                min="<?= e($ticket['transaction_date']) ?>"
                                value="<?= e($ticket['return_date']) ?>"
                                required
                            >
                        </div>

                        <div class="tl-issuance-field">
                            <label for="correct_return_remarks">
                                Return Remarks
                            </label>
                            <textarea
                                id="correct_return_remarks"
                                name="remarks"
                                maxlength="5000"
                                rows="3"
                            ><?= e($ticket['return_remarks'] ?? '') ?></textarea>
                        </div>

                        <button type="submit"
                                class="app-button app-button-primary">
                            Save Correction
                        </button>
                    </form>

                    <hr class="tl-detail-divider">

                    <h3 class="tl-detail-subheading">
                        Reverse Recorded Return
                    </h3>

                    <p class="tl-detail-help">
                        Use this only for an incorrectly recorded return.
                        The record will be preserved in the audit history.
                        The ticket will again have no active recorded return.
                    </p>

                    <form method="POST"
                          class="tl-detail-form"
                          data-confirm="Reverse this recorded return? This action will be audited.">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e(csrfToken()) ?>">

                        <input type="hidden"
                               name="action"
                               value="reverse">
                        <input type="hidden" name="return_id" value="<?= (int) $ticket['return_id'] ?>">

                        <div class="tl-issuance-field">
                            <label for="reversal_reason">
                                Reason for Reversal *
                            </label>
                            <textarea
                                id="reversal_reason"
                                name="remarks"
                                maxlength="5000"
                                rows="3"
                                required
                                placeholder="Explain why this return should be reversed"
                            ></textarea>
                        </div>

                        <button type="submit"
                                class="app-button app-button-danger">
                            Reverse Return
                        </button>
                    </form>

                <?php endif; ?>
            </section>

            <section class="app-card">
                <h2 class="tl-section-heading">Audit History</h2>

                <?php if (!$auditLogs): ?>
                    <div class="app-empty-state">
                        <h3>No audit history found</h3>
                        <p>No actions have been logged for this ticket.</p>
                    </div>
                <?php else: ?>
                    <div class="tl-audit-list">
                        <?php foreach ($auditLogs as $log): ?>
                            <article class="tl-audit-entry">
                                <div class="tl-audit-marker"
                                     aria-hidden="true"></div>

                                <div class="tl-audit-content">
                                    <strong>
                                        <?= e(str_replace(
                                            '_',
                                            ' ',
                                            $log['action']
                                        )) ?>
                                    </strong>

                                    <p class="tl-audit-meta">
                                        <?= e($log['staff_name']) ?>
                                        &middot;
                                        <?= e($log['created_at']) ?>
                                    </p>

                                    <?php if ($log['old_values']): ?>
                                        <p>
                                            <strong>Previous:</strong>
                                            <?= e(auditSummary(
                                                $log['old_values']
                                            )) ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($log['new_values']): ?>
                                        <p>
                                            <strong>Updated:</strong>
                                            <?= e(auditSummary(
                                                $log['new_values']
                                            )) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const successMessage = document.querySelector('[data-toast-message]');

    if (successMessage && typeof window.tripLedgerToast === 'function') {
        window.tripLedgerToast(
            successMessage.dataset.toastMessage,
            'success'
        );
    }
});
</script>

</body>
</html>
