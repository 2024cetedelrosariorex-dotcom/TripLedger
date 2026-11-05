<?php

require_once __DIR__ . '/db.php';

// ==========================================
// INPUT NORMALIZATION
// ==========================================

function normalizeTripLedgerPlate(string $plate): string
{
    $plate = strtoupper(trim($plate));

    return preg_replace('/\s+/', ' ', $plate);
}

function normalizeTripLedgerDriver(string $name): string
{
    $name = trim($name);

    return preg_replace('/\s+/u', ' ', $name);
}

// ==========================================
// VALIDATE VEHICLE PLATE NUMBER
// ==========================================

function validateTripLedgerPlate(string $plate): void
{
    if (
        strlen($plate) < 4 ||
        strlen($plate) > 30
    ) {
        throw new InvalidArgumentException(
            'Vehicle plate number must contain 4 to 30 characters.'
        );
    }

    // Preliminary plate validation.
    // Allow uppercase letters, numbers,
    // spaces and hyphens.
    if (!preg_match('/^[A-Z0-9 -]+$/', $plate)) {
        throw new InvalidArgumentException(
            'Vehicle plate number contains invalid characters.'
        );
    }

    // Require at least one letter and one digit.
    if (
        !preg_match('/[A-Z]/', $plate) ||
        !preg_match('/[0-9]/', $plate)
    ) {
        throw new InvalidArgumentException(
            'Vehicle plate number must contain both letters and numbers.'
        );
    }
}

// ==========================================
// VALIDATE DRIVER NAME
// ==========================================

function validateTripLedgerDriver(string $name): void
{
    if (!preg_match(
        "/^(?=.*\p{L})[\p{L}\p{M} .'-]+$/u",
        $name
    )) {
        throw new InvalidArgumentException(
            "Driver's name must contain letters and " .
            "cannot contain numbers or invalid symbols."
        );
    }

    // Length in Unicode characters.
    // This avoids counting multibyte letters
    // as multiple characters.
    $length = preg_match_all('/./us', $name);

    if (
        $length === false ||
        $length < 2 ||
        $length > 150
    ) {
        throw new InvalidArgumentException(
            "Driver's name must contain 2 to 150 characters."
        );
    }
}

// ==========================================
// VALIDATE TRANSACTION DATE
// ==========================================

function validateTripLedgerDate(
    string $transactionDate
): DateTimeImmutable {

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $transactionDate
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid transaction date.'
        );
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $transactionDate
    );

    if (
        $date === false ||
        $date->format('Y-m-d') !== $transactionDate || (int) $date->format('Y') < 1000
    ) {
        throw new InvalidArgumentException(
            'Invalid transaction date.'
        );
    }

    return $date;
}

// ==========================================
// FIND EXISTING SUBMISSION
// ==========================================

function findExistingSubmission(
    string $requestToken,
    int $staffId
): ?string {

    if (
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $requestToken
        )
    ) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT ticket_number
         FROM trip_tickets
         WHERE submission_token = ?
           AND created_by = ?
         LIMIT 1'
    );

    $stmt->execute([
        $requestToken,
        $staffId
    ]);

    $ticketNumber = $stmt->fetchColumn();

    return $ticketNumber === false
        ? null
        : (string) $ticketNumber;
}

// ==========================================
// CREATE NEW TRIP TICKET
// ==========================================

function createTripTicket(
    int $staffId,
    string $transactionDate,
    string $plateNumber,
    string $driverName,
    string $remarks,
    string $requestToken
): string {

    $pdo = db();

    // ======================================
    // 1. VALIDATE STAFF ID
    // ======================================

    if ($staffId < 1) {
        throw new InvalidArgumentException(
            'Invalid staff account.'
        );
    }

    // ======================================
    // 2. NORMALIZE INPUT
    // ======================================

    $transactionDate = trim($transactionDate);

    $plateNumber = normalizeTripLedgerPlate(
        $plateNumber
    );

    $driverName = normalizeTripLedgerDriver(
        $driverName
    );

    $remarks = trim($remarks);

    // ======================================
    // 3. SERVER-SIDE VALIDATION
    // ======================================

    $date = validateTripLedgerDate(
        $transactionDate
    );

    validateTripLedgerPlate($plateNumber);

    validateTripLedgerDriver($driverName);

    if (strlen($remarks) > 5000) {
        throw new InvalidArgumentException(
            'Remarks are too long.'
        );
    }

    if (
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $requestToken
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid submission token.'
        );
    }

    // ======================================
    // 4. PREPARE NUMBERING
    // ======================================

    $year = (int) $date->format('Y');
    $month = $date->format('m');

    $config = tripledgerConfig();

    $width = (int) (
        $config['ticket_numbering']['sequence_width'] ?? 4
    );

    if ($width < 4 || $width > 9) {
        throw new RuntimeException(
            'Invalid ticket sequence width.'
        );
    }

    // ======================================
    // 5. START DATABASE TRANSACTION
    // ======================================

    try {

        $pdo->beginTransaction();

        // ==================================
        // CHECK EXISTING SUBMISSION
        // ==================================

        $existing = $pdo->prepare(
            'SELECT ticket_number
             FROM trip_tickets
             WHERE submission_token = ?
               AND created_by = ?
             LIMIT 1'
        );

        $existing->execute([
            $requestToken,
            $staffId
        ]);

        $existingNumber = $existing->fetchColumn();

        if ($existingNumber !== false) {

            $pdo->commit();

            return (string) $existingNumber;
        }

        // ==================================
        // ALLOCATE ANNUAL SEQUENCE
        // ==================================

        $counter = $pdo->prepare(
            'INSERT INTO annual_counters
                (ticket_year, last_sequence)
             VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE
                last_sequence = LAST_INSERT_ID(
                    last_sequence + 1
                )'
        );

        $counter->execute([$year]);

        $sequence = (int) $pdo->query(
            'SELECT LAST_INSERT_ID()'
        )->fetchColumn();

        if (
            $sequence < 1 ||
            $sequence > (10 ** $width) - 1
        ) {
            throw new RuntimeException(
                'Annual ticket sequence is full.'
            );
        }

        // ==================================
        // GENERATE TICKET NUMBER
        // ==================================

        $ticketNumber = sprintf(
            '%04d-%s-%0' . $width . 'd',
            $year,
            $month,
            $sequence
        );

        // ==================================
        // FIND OR CREATE VEHICLE
        // ==================================

        $vehicle = $pdo->prepare(
            'INSERT INTO vehicles (plate_number)
             VALUES (?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id)'
        );

        $vehicle->execute([
            $plateNumber
        ]);

        $vehicleId = (int) $pdo->query(
            'SELECT LAST_INSERT_ID()'
        )->fetchColumn();

        // ==================================
        // FIND OR CREATE DRIVER
        // ==================================

        $driver = $pdo->prepare(
            'SELECT id
             FROM drivers
             WHERE full_name = ?
             ORDER BY id ASC
             LIMIT 1'
        );

        $driver->execute([
            $driverName
        ]);

        $driverId = $driver->fetchColumn();

        if ($driverId === false) {

            $insertDriver = $pdo->prepare(
                'INSERT INTO drivers (full_name)
                 VALUES (?)'
            );

            $insertDriver->execute([
                $driverName
            ]);

            $driverId = (int) $pdo->lastInsertId();
        }

        // ==================================
        // SAVE TRIP TICKET
        // ==================================

        $insert = $pdo->prepare(
            'INSERT INTO trip_tickets
            (
                ticket_number,
                ticket_year,
                ticket_sequence,
                transaction_date,
                vehicle_id,
                driver_id,
                remarks,
                created_by,
                updated_by,
                submission_token
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $insert->execute([
            $ticketNumber,
            $year,
            $sequence,
            $transactionDate,
            $vehicleId,
            (int) $driverId,
            $remarks !== '' ? $remarks : null,
            $staffId,
            $staffId,
            $requestToken
        ]);

        $ticketId = (int) $pdo->lastInsertId();

        // ==================================
        // AUDIT TRAIL
        // ==================================

        $audit = $pdo->prepare(
            'INSERT INTO audit_logs
            (
                user_id,
                trip_ticket_id,
                action,
                old_values,
                new_values
            )
            VALUES (?, ?, ?, NULL, ?)'
        );

        $audit->execute([
            $staffId,
            $ticketId,
            'TICKET_CREATED',
            json_encode([
                'ticket_number' => $ticketNumber,
                'transaction_date' => $transactionDate,
                'plate_number' => $plateNumber,
                'driver_name' => $driverName,
                'remarks' => $remarks
            ], JSON_THROW_ON_ERROR)
        ]);

        // ==================================
        // COMMIT
        // ==================================

        $pdo->commit();

        return $ticketNumber;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // ==================================
        // DUPLICATE SUBMISSION RECOVERY
        // ==================================

        if (
            $e instanceof PDOException &&
            $e->getCode() === '23000' &&
            (int) ($e->errorInfo[1] ?? 0) === 1062
        ) {

            $existingNumber = findExistingSubmission(
                $requestToken,
                $staffId
            );

            if ($existingNumber !== null) {
                return $existingNumber;
            }
        }

        throw $e;
    }
}
