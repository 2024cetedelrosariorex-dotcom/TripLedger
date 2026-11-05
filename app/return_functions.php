<?php

require_once __DIR__ . '/db.php';

function validateReturnDate(string $value, string $transactionDate): void
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Please enter a valid return date.');
    }

    if ($value < $transactionDate) {
        throw new InvalidArgumentException(
            'Return date cannot be earlier than the transaction date.'
        );
    }
}

function saveReturnAction(
    int $ticketId,
    int $staffId,
    string $action,
    ?string $returnDate = null,
    string $remarks = '',
    ?int $expectedReturnId = null
): void {
    if ($ticketId < 1 || $staffId < 1) {
        throw new InvalidArgumentException('Invalid ticket or staff account.');
    }

    if (!in_array($action, ['record', 'correct', 'reverse'], true)) {
        throw new InvalidArgumentException('Invalid return action.');
    }

    $remarks = trim($remarks);

    if (strlen($remarks) > 5000) {
        throw new InvalidArgumentException('Remarks are too long.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Lock the issued ticket so concurrent return actions
        // cannot modify its return status simultaneously.
        $stmt = $pdo->prepare(
            'SELECT id, transaction_date
             FROM trip_tickets
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            throw new InvalidArgumentException('Trip ticket not found.');
        }

        // Get the specific active return for this ticket.
        $stmt = $pdo->prepare(
            'SELECT id, return_date, remarks, recorded_by
             FROM ticket_returns
             WHERE trip_ticket_id = ?
               AND reversed_at IS NULL
             FOR UPDATE'
        );
        $stmt->execute([$ticketId]);
        $activeReturn = $stmt->fetch();

        if ($expectedReturnId !== null && (!$activeReturn || (int) $activeReturn['id'] !== $expectedReturnId)) {
            throw new InvalidArgumentException('The return record has changed. Refresh the ticket before trying again.');
        }

        $oldValues = null;
        $newValues = null;
        $auditAction = '';

        if ($action === 'record') {
            if ($activeReturn) {
                throw new InvalidArgumentException(
                    'This trip ticket already has a recorded return.'
                );
            }

            validateReturnDate(
                (string) $returnDate,
                $ticket['transaction_date']
            );

            $stmt = $pdo->prepare(
                'INSERT INTO ticket_returns
                 (trip_ticket_id, return_date, remarks, recorded_by)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $ticketId,
                $returnDate,
                $remarks !== '' ? $remarks : null,
                $staffId
            ]);

            $newValues = [
                'return_id' => (int) $pdo->lastInsertId(),
                'return_date' => $returnDate,
                'remarks' => $remarks,
                'recorded_by' => $staffId
            ];

            $auditAction = 'RETURN_RECORDED';

        } elseif ($action === 'correct') {
            if (!$activeReturn) {
                throw new InvalidArgumentException(
                    'No active return is available for correction.'
                );
            }

            validateReturnDate(
                (string) $returnDate,
                $ticket['transaction_date']
            );

            $oldValues = [
                'return_id' => (int) $activeReturn['id'],
                'return_date' => $activeReturn['return_date'],
                'remarks' => $activeReturn['remarks']
            ];

            $newValues = [
                'return_id' => (int) $activeReturn['id'],
                'return_date' => $returnDate,
                'remarks' => $remarks
            ];

            if (
                $oldValues['return_date'] === $returnDate &&
                (string) ($oldValues['remarks'] ?? '') === $remarks
            ) {
                throw new InvalidArgumentException(
                    'No changes were made to the return record.'
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE ticket_returns
                 SET return_date = ?, remarks = ?
                 WHERE id = ? AND reversed_at IS NULL'
            );
            $stmt->execute([
                $returnDate,
                $remarks !== '' ? $remarks : null,
                $activeReturn['id']
            ]);

            $auditAction = 'RETURN_CORRECTED';

        } elseif ($action === 'reverse') {
            if (!$activeReturn) {
                throw new InvalidArgumentException(
                    'No active return is available for reversal.'
                );
            }

            if ($remarks === '') {
                throw new InvalidArgumentException(
                    'Please provide a reason for reversing the return.'
                );
            }

            $oldValues = [
                'return_id' => (int) $activeReturn['id'],
                'return_date' => $activeReturn['return_date'],
                'remarks' => $activeReturn['remarks']
            ];

            $stmt = $pdo->prepare(
                'UPDATE ticket_returns
                 SET reversed_by = ?, reversed_at = NOW()
                 WHERE id = ? AND reversed_at IS NULL'
            );
            $stmt->execute([
                $staffId,
                $activeReturn['id']
            ]);

            $newValues = [
                'return_id' => (int) $activeReturn['id'],
                'status' => 'Reversed',
                'reason' => $remarks,
                'reversed_by' => $staffId
            ];

            $auditAction = 'RETURN_REVERSED';
        }

        // Record the staff member who last updated the ticket.
        $stmt = $pdo->prepare(
            'UPDATE trip_tickets
             SET updated_by = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$staffId, $ticketId]);

        // Save changes in the permanent audit history.
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
             (user_id, trip_ticket_id, action, old_values, new_values)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $staffId,
            $ticketId,
            $auditAction,
            $oldValues === null
                ? null
                : json_encode($oldValues, JSON_THROW_ON_ERROR),
            $newValues === null
                ? null
                : json_encode($newValues, JSON_THROW_ON_ERROR)
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
