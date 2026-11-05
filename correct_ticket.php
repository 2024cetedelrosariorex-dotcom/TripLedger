<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/correction_functions.php';

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

$pageTitle = 'Correct Issuance';
$error = '';

$stmt = db()->prepare(
    'SELECT
        t.id,
        t.ticket_number,
        t.transaction_date,
        t.remarks,
        v.plate_number,
        d.full_name AS driver_name
     FROM trip_tickets t
     JOIN vehicles v ON v.id = t.vehicle_id
     JOIN drivers d ON d.id = t.driver_id
     WHERE t.id = ?
     LIMIT 1'
);

$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    http_response_code(404);
    exit('Trip ticket not found.');
}

$values = [
    'transaction_date' => $ticket['transaction_date'],
    'plate_number' => $ticket['plate_number'],
    'driver_name' => $ticket['driver_name'],
    'remarks' => $ticket['remarks'] ?? ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $inputValid = true;

    foreach ($values as $field => $default) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            $inputValid = false;
            break;
        }

        $values[$field] = trim($_POST[$field]);
    }

    if (!$inputValid) {
        $error = 'Invalid form data. Please review the fields.';
    } else {
        try {
            correctTripTicket(
                (int) $ticketId,
                (int) $user['id'],
                $values['transaction_date'],
                $values['plate_number'],
                $values['driver_name'],
                $values['remarks']
            );

            $_SESSION['ticket_success'] =
                'Issuance information corrected successfully.';

            header(
                'Location: ticket_details.php?id=' . $ticketId,
                true,
                303
            );
            exit;

        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();

        } catch (Throwable $e) {
            error_log('Issuance correction failed: ' . $e->getMessage());
            $error = 'Unable to save correction. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Correct Issuance | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>
</head>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const form = document.querySelector(
        'form[action^="correct_ticket.php"]'
    );

    const plateInput = document.getElementById('plate_number');
    const driverInput = document.getElementById('driver_name');
    const dateInput = document.getElementById('transaction_date');

    if (!form || !plateInput || !driverInput || !dateInput) {
        return;
    }

    function validatePlate() {
        const plate = plateInput.value.trim().toUpperCase();

        const valid =
            plate.length >= 4 &&
            plate.length <= 30 &&
            /^[A-Z0-9 -]+$/.test(plate) &&
            /[A-Z]/.test(plate) &&
            /[0-9]/.test(plate);

        plateInput.setCustomValidity(
            valid
                ? ''
                : 'Plate number must contain letters and numbers.'
        );
    }

    function validateDriver() {
        const name = driverInput.value.trim();

        const valid =
            name.length >= 2 &&
            name.length <= 150 &&
            /^(?=.*\p{L})[\p{L}\p{M} .'-]+$/u.test(name);

        driverInput.setCustomValidity(
            valid
                ? ''
                : "Driver's name cannot contain numbers or invalid symbols."
        );
    }

    plateInput.addEventListener('input', validatePlate);
    driverInput.addEventListener('input', validateDriver);

    form.addEventListener('submit', function (event) {

        validatePlate();
        validateDriver();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

});
</script>


<body>

<div class="app-layout">

    <?php require __DIR__ . '/../app/partials/sidebar.php'; ?>

    <div class="tl-content">

        <?php require __DIR__ . '/../app/partials/header.php'; ?>

        <main class="tl-dashboard-body">

            <div class="tl-detail-back">
                <a href="ticket_details.php?id=<?= (int) $ticketId ?>">
                    &larr; Back to Ticket Details
                </a>
            </div>

            <section class="tl-welcome">
                <h2>Correct Issuance Information</h2>
                <p>
                    Review and correct the selected trip ticket.
                    Every successful correction is recorded
                    in the audit history.
                </p>
            </section>

            <?php if ($error !== ''): ?>
                <div class="tl-issuance-alert tl-alert-error"
                     role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="app-card tl-issuance-card">

                <div class="tl-issuance-heading">
                    <div>
                        <h2>Ticket Information</h2>
                        <p>
                            Update the details that require correction.
                        </p>
                    </div>

                    <span class="tl-form-label">
                        Correction
                    </span>
                </div>

                <div class="tl-correction-notice">
                    <strong>Ticket Number Cannot Be Changed</strong>

                    <p>
                        The assigned ticket number is permanent.
                        Changes to the transaction year or month
                        require manual review and are not allowed
                        through this form.
                    </p>
                </div>

                <form
                    method="POST"
                    action="correct_ticket.php?id=<?= (int) $ticketId ?>"
                    data-confirm="Confirm this issuance correction? Previous and updated values will be recorded in the audit history."
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <div class="tl-issuance-grid">

                        <div class="tl-issuance-field">
                            <label for="ticket_number">
                                Ticket Number
                            </label>

                            <input
                                type="text"
                                id="ticket_number"
                                value="<?= e($ticket['ticket_number']) ?>"
                                readonly
                                class="tl-readonly-input"
                            >

                            <small>
                                Automatically generated and permanently assigned.
                            </small>
                        </div>

                        <div class="tl-issuance-field">
                            <label for="transaction_date">
                                Transaction Date *
                            </label>

                            <input
                                type="date"
                                id="transaction_date"
                                name="transaction_date"
                                value="<?= e($values['transaction_date']) ?>"
                                required
                            >

                            <small>
                                Only corrections within the original
                                transaction year and month are permitted.
                            </small>
                        </div>

                        <div class="tl-issuance-field">
                            <label for="plate_number">
                                Vehicle Plate Number *
                            </label>

                            <input
                                type="text"
                                id="plate_number"
                                name="plate_number"
                                maxlength="30"
                                value="<?= e($values['plate_number']) ?>"
                                required
                            >
                        </div>

                        <div class="tl-issuance-field">
                            <label for="driver_name">
                                Driver's Name *
                            </label>

                            <input
                                type="text"
                                id="driver_name"
                                name="driver_name"
                                maxlength="150"
                                value="<?= e($values['driver_name']) ?>"
                                required
                            >
                        </div>

                        <div class="tl-issuance-field tl-full-width">
                            <label for="remarks">
                                Issuance Remarks (Optional)
                            </label>

                            <textarea
                                id="remarks"
                                name="remarks"
                                maxlength="5000"
                                rows="4"
                                placeholder="Additional notes"
                            ><?= e($values['remarks']) ?></textarea>
                        </div>

                    </div>

                    <div class="tl-issuance-actions">

                        <a
                            href="ticket_details.php?id=<?= (int) $ticketId ?>"
                            class="app-button app-button-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="app-button app-button-primary"
                        >
                            Save Correction
                        </button>

                    </div>

                </form>

            </section>

        </main>
    </div>

</div>

</body>
</html>
