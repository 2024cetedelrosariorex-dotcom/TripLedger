<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ticket_functions.php';

requireLogin();

$user = currentUser();

if (!$user) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Record New Issuance';
$error = '';
$success = '';

// ==========================================
// SUBMISSION TOKEN
// ==========================================

if (empty($_SESSION['issuance_token'])) {
    $_SESSION['issuance_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// FORM VALIDATION
// ==========================================

function validateIssuanceDriver(string $name): bool
{
    $name = trim($name);

    if (
        mb_strlen($name, 'UTF-8') < 2 ||
        mb_strlen($name, 'UTF-8') > 150
    ) {
        return false;
    }

    return preg_match(
        "/^(?=.*\p{L})[\p{L}\p{M} .'-]+$/u",
        $name
    ) === 1;
}

function validateIssuancePlate(string $plate): bool
{
    $plate = strtoupper(trim($plate));

    if (strlen($plate) < 4 || strlen($plate) > 30) {
        return false;
    }

    return preg_match('/^[A-Z0-9 -]+$/', $plate) === 1
        && preg_match('/[A-Z]/', $plate) === 1
        && preg_match('/[0-9]/', $plate) === 1;
}

function validateIssuanceDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

// ==========================================
// HANDLE ISSUANCE
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrf();

    $submittedToken = $_POST['submission_token'] ?? '';
    $transactionDate = $_POST['transaction_date'] ?? '';
    $plateNumber = $_POST['plate_number'] ?? '';
    $driverName = $_POST['driver_name'] ?? '';
    $remarks = $_POST['remarks'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($transactionDate) ||
        !is_string($plateNumber) ||
        !is_string($driverName) ||
        !is_string($remarks)
    ) {
        $error = 'Invalid form data.';

    } elseif (
        !hash_equals(
            $_SESSION['issuance_token'],
            $submittedToken
        )
    ) {
        $existingNumber = findExistingSubmission($submittedToken, (int) $user['id']);
        if ($existingNumber !== null) {
            $_SESSION['issuance_success'] = 'Trip Ticket ' . $existingNumber . ' was already recorded. No duplicate was created.';
            header('Location: issuance.php', true, 303);
            exit;
        }
        $error = 'This form has expired. Please refresh the page.';

    } else {

        $transactionDate = trim($transactionDate);
        $plateNumber = strtoupper(trim($plateNumber));
        $driverName = trim($driverName);
        $remarks = trim($remarks);

        if (!validateIssuanceDate($transactionDate)) {

            $error = 'Please enter a valid transaction date.';

        } elseif (!validateIssuancePlate($plateNumber)) {

            $error =
                'Vehicle plate number must contain ' .
                'letters and numbers and use a valid format.';

        } elseif (!validateIssuanceDriver($driverName)) {

            $error =
                "Driver's name must contain letters and " .
                "cannot contain numbers or invalid symbols.";

        } elseif (mb_strlen($remarks, 'UTF-8') > 5000) {

            $error = 'Remarks must not exceed 5,000 characters.';

        } else {

            try {

                $ticketNumber = createTripTicket(
                    (int) $user['id'],
                    $transactionDate,
                    $plateNumber,
                    $driverName,
                    $remarks,
                    $submittedToken
                );

                $_SESSION['issuance_success'] =
                    'Trip Ticket ' . $ticketNumber .
                    ' successfully recorded!';

                // Fresh token for the next issuance.
                $_SESSION['issuance_token'] =
                    bin2hex(random_bytes(32));

                header('Location: issuance.php', true, 303);
                exit;

            } catch (InvalidArgumentException $e) {

                $error = $e->getMessage();

            } catch (Throwable $e) {

                error_log(
                    'TripLedger issuance error: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to save the issuance. Please try again.';
            }
        }
    }
}

// ==========================================
// SUCCESS MESSAGE
// ==========================================

if (isset($_SESSION['issuance_success'])) {
    $success = (string) $_SESSION['issuance_success'];
    unset($_SESSION['issuance_success']);
}

// ==========================================
// LOAD EXISTING VEHICLES AND DRIVERS
// ==========================================

$vehicles = db()->query(
    'SELECT plate_number
     FROM vehicles
     ORDER BY plate_number'
)->fetchAll(PDO::FETCH_COLUMN);

$drivers = db()->query(
    'SELECT DISTINCT full_name
     FROM drivers
     ORDER BY full_name'
)->fetchAll(PDO::FETCH_COLUMN);

// ==========================================
// RESTORE FORM VALUES
// ==========================================

$old = [
    'transaction_date' => date('Y-m-d'),
    'plate_number' => '',
    'driver_name' => '',
    'remarks' => ''
];

if ($error !== '') {
    foreach ($old as $field => $default) {
        if (
            isset($_POST[$field]) &&
            is_string($_POST[$field])
        ) {
            $old[$field] = $_POST[$field];
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>New Issuance | TripLedger</title>

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

            <section class="tl-welcome">
                <h2>Record New Trip Ticket</h2>

                <p>
                    Enter the issuance information below.
                    A unique trip ticket number will be
                    generated automatically after saving.
                </p>
            </section>

            <?php if ($success !== ''): ?>
                <div
                    class="tl-issuance-alert tl-alert-success"
                    role="status"
                    data-toast-message="<?= e($success) ?>"
                    data-toast-type="success"
                >
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div
                    class="tl-issuance-alert tl-alert-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="app-card tl-issuance-card">

                <div class="tl-issuance-heading">
                    <div>
                        <h2>Issuance Information</h2>
                        <p>Fields marked * are required.</p>
                    </div>

                    <span class="tl-form-label">
                        New Record
                    </span>
                </div>

                <form
                    method="POST"
                    action="issuance.php"
                    id="issuanceForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="submission_token"
                        value="<?= e($_SESSION['issuance_token']) ?>"
                    >

                    <div class="tl-issuance-grid">

                        <!-- TRANSACTION DATE -->

                        <div class="tl-issuance-field">

                            <label for="transaction_date">
                                Transaction Date *
                            </label>

                            <input
                                type="date"
                                name="transaction_date"
                                id="transaction_date"
                                value="<?= e($old['transaction_date']) ?>"
                                required
                            >

                            <small>
                                The ticket year and month will
                                follow this date.
                            </small>

                        </div>

                        <!-- VEHICLE PLATE NUMBER -->

                        <div class="tl-issuance-field">

                            <label for="plate_number">
                                Vehicle Plate Number *
                            </label>

                            <input
                                type="text"
                                name="plate_number"
                                id="plate_number"
                                list="vehicleList"
                                minlength="4"
                                maxlength="30"
                                placeholder="Example: ABC 1234"
                                value="<?= e($old['plate_number']) ?>"
                                autocomplete="off"
                                required
                            >

                            <datalist id="vehicleList">
                                <?php foreach ($vehicles as $plate): ?>
                                    <option value="<?= e($plate) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>

                            <small>
                                Select an existing vehicle or enter
                                a new plate number containing
                                letters and numbers.
                            </small>

                        </div>

                        <!-- DRIVER'S NAME -->

                        <div class="tl-issuance-field tl-full-width">

                            <label for="driver_name">
                                Driver's Name *
                            </label>

                            <input
                                type="text"
                                name="driver_name"
                                id="driver_name"
                                list="driverList"
                                minlength="2"
                                maxlength="150"
                                placeholder="Enter driver's full name"
                                value="<?= e($old['driver_name']) ?>"
                                autocomplete="off"
                                required
                            >

                            <datalist id="driverList">
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= e($driver) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>

                            <small>
                                Choose a recorded driver or enter
                                a name using letters, spaces,
                                hyphens, apostrophes, or periods.
                            </small>

                        </div>

                        <!-- ISSUANCE REMARKS -->

                        <div class="tl-issuance-field tl-full-width">

                            <label for="remarks">
                                Issuance Remarks
                                <span class="tl-optional">
                                    (Optional)
                                </span>
                            </label>

                            <textarea
                                name="remarks"
                                id="remarks"
                                maxlength="5000"
                                rows="4"
                                placeholder="Additional trip ticket information"
                            ><?= e($old['remarks']) ?></textarea>

                        </div>

                    </div>

                    <!-- AUTOMATED NUMBERING NOTE -->

                    <div class="tl-issuance-note">

                        <strong>Automated Numbering</strong>

                        <p>
                            TripLedger allocates a unique ticket number
                            using the transaction year, month, and annual
                            sequence. Once saved, the ticket number
                            cannot be edited.
                        </p>

                    </div>

                    <!-- FORM ACTIONS -->

                    <div class="tl-issuance-actions">

                        <a
                            href="records.php"
                            class="app-button app-button-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="app-button app-button-primary"
                            id="saveButton"
                        >
                            Save Issuance
                        </button>

                    </div>

                </form>

            </section>

        </main>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('issuanceForm');
    const saveButton = document.getElementById('saveButton');

    const plateInput = document.getElementById('plate_number');
    const driverInput = document.getElementById('driver_name');

    // ======================================
    // DRIVER NAME VALIDATION
    // ======================================

    function validateDriverName() {

        const name = driverInput.value.trim();

        const validName =
            /^(?=.*\p{L})[\p{L}\p{M} .'-]+$/u;

        if (name === '') {

            driverInput.setCustomValidity(
                "Driver's name is required."
            );

        } else if (
            name.length < 2 ||
            !validName.test(name)
        ) {

            driverInput.setCustomValidity(
                "Driver's name must contain letters " +
                "and cannot contain numbers or invalid symbols."
            );

        } else {

            driverInput.setCustomValidity('');
        }
    }

    // ======================================
    // PLATE NUMBER VALIDATION
    // ======================================

    function validatePlateNumber() {

        const plate = plateInput.value.trim().toUpperCase();

        const validCharacters = /^[A-Z0-9 -]+$/;

        const hasLetters = /[A-Z]/.test(plate);
        const hasNumbers = /[0-9]/.test(plate);

        if (plate === '') {

            plateInput.setCustomValidity(
                'Vehicle plate number is required.'
            );

        } else if (
            plate.length < 4 ||
            plate.length > 30 ||
            !validCharacters.test(plate) ||
            !hasLetters ||
            !hasNumbers
        ) {

            plateInput.setCustomValidity(
                'Enter a valid plate number containing ' +
                'letters and numbers, such as ABC 1234.'
            );

        } else {

            plateInput.setCustomValidity('');
        }
    }

    // ======================================
    // VALIDATION EVENTS
    // ======================================

    if (plateInput && driverInput) {

        plateInput.addEventListener(
            'input',
            validatePlateNumber
        );

        plateInput.addEventListener(
            'change',
            validatePlateNumber
        );

        driverInput.addEventListener(
            'input',
            validateDriverName
        );

        driverInput.addEventListener(
            'change',
            validateDriverName
        );
    }

    // ======================================
    // FORM SUBMISSION
    // ======================================

    if (form && saveButton) {

        form.addEventListener('submit', function (event) {

            validatePlateNumber();
            validateDriverName();

            if (!form.checkValidity()) {

                event.preventDefault();
                form.reportValidity();
                return;
            }

            if (saveButton.disabled) {

                event.preventDefault();
                return;
            }

            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
        });

        // Restore button when navigating back.
        window.addEventListener('pageshow', function () {

            saveButton.disabled = false;
            saveButton.textContent = 'Save Issuance';
        });
    }

    // ======================================
    // SUCCESS TOAST
    // ======================================

    const successElement = document.querySelector(
        '[data-toast-message]'
    );

    if (successElement && window.tripLedgerToast) {

        window.tripLedgerToast(
            successElement.dataset.toastMessage,
            successElement.dataset.toastType || 'success'
        );
    }

});
</script>

</body>
</html>
