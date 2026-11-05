<?php

require_once __DIR__ . '/ticket_functions.php';

function correctTripTicket(
    int $ticketId,
    int $staffId,
    string $transactionDate,
    string $plateNumber,
    string $driverName,
    string $remarks
): void {
    $plateNumber = normalizeTripLedgerPlate($plateNumber);
    $driverName = normalizeTripLedgerDriver($driverName);
    $remarks = trim($remarks);
    validateTripLedgerDate($transactionDate);
    validateTripLedgerPlate($plateNumber);
    validateTripLedgerDriver($driverName);

    if (strlen($remarks) > 5000) {
        throw new InvalidArgumentException('Remarks are too long.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT t.*, v.plate_number, d.full_name AS driver_name
             FROM trip_tickets t
             JOIN vehicles v ON v.id = t.vehicle_id
             JOIN drivers d ON d.id = t.driver_id
             WHERE t.id = ?
             FOR UPDATE'
        );
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            throw new InvalidArgumentException('Trip ticket not found.');
        }

        if (
            substr($transactionDate, 0, 7) !==
            substr($ticket['transaction_date'], 0, 7)
        ) {
            throw new InvalidArgumentException(
                'Changing the transaction year or month requires manual review.'
            );
        }

        // A recorded physical return must not precede
        // the corrected transaction date.
        $stmt = $pdo->prepare(
            'SELECT return_date
             FROM ticket_returns
             WHERE trip_ticket_id = ?
               AND reversed_at IS NULL
             FOR UPDATE'
        );
        $stmt->execute([$ticketId]);
        $activeReturnDate = $stmt->fetchColumn();

        if ($activeReturnDate && $activeReturnDate < $transactionDate) {
            throw new InvalidArgumentException(
                'The corrected transaction date cannot be after the recorded return date.'
            );
        }

        $oldValues = [
            'transaction_date' => $ticket['transaction_date'],
            'plate_number' => $ticket['plate_number'],
            'driver_name' => $ticket['driver_name'],
            'remarks' => $ticket['remarks']
        ];

        $newValues = [
            'transaction_date' => $transactionDate,
            'plate_number' => $plateNumber,
            'driver_name' => $driverName,
            'remarks' => $remarks
        ];

        if (
            $oldValues['transaction_date'] === $transactionDate &&
            $oldValues['plate_number'] === $plateNumber &&
            $oldValues['driver_name'] === $driverName &&
            (string) ($oldValues['remarks'] ?? '') === $remarks
        ) {
            throw new InvalidArgumentException('No changes detected.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO vehicles (plate_number)
             VALUES (?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$plateNumber]);

        $vehicleId = (int) $pdo->query(
            'SELECT LAST_INSERT_ID()'
        )->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id FROM drivers WHERE full_name = ?
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$driverName]);
        $driverId = $stmt->fetchColumn();

        if (!$driverId) {
            $stmt = $pdo->prepare(
                'INSERT INTO drivers (full_name) VALUES (?)'
            );
            $stmt->execute([$driverName]);
            $driverId = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'UPDATE trip_tickets
             SET transaction_date = ?,
                 vehicle_id = ?,
                 driver_id = ?,
                 remarks = ?,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );

        $stmt->execute([
            $transactionDate,
            $vehicleId,
            $driverId,
            $remarks !== '' ? $remarks : null,
            $staffId,
            $ticketId
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
             (user_id, trip_ticket_id, action, old_values, new_values)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $staffId,
            $ticketId,
            'ISSUANCE_CORRECTED',
            json_encode($oldValues, JSON_THROW_ON_ERROR),
            json_encode($newValues, JSON_THROW_ON_ERROR)
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
