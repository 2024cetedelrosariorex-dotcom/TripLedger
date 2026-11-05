<?php

require_once __DIR__ . '/db.php';

function reportFilters(array $input): array
{
    foreach (['plate', 'date_from', 'date_to'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Invalid report filter.');
    }
    $plate = trim((string) ($input['plate'] ?? ''));
    $from = trim((string) ($input['date_from'] ?? ''));
    $to = trim((string) ($input['date_to'] ?? ''));

    if (strlen($plate) > 30) {
        throw new InvalidArgumentException('Plate filter is too long.');
    }

    foreach ([$from, $to] as $value) {
        if ($value === '') {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid date filter.');
        }
    }

    if ($from !== '' && $to !== '' && $from > $to) {
        throw new InvalidArgumentException(
            'Date From cannot be later than Date To.'
        );
    }

    return [
        'plate' => $plate,
        'date_from' => $from,
        'date_to' => $to
    ];
}

function outstandingRecords(PDO $pdo, array $filters): array
{
    $sql = "
        SELECT
            t.id,
            t.ticket_number,
            t.transaction_date,
            v.plate_number,
            d.full_name AS driver_name
        FROM trip_tickets t
        JOIN vehicles v ON v.id = t.vehicle_id
        JOIN drivers d ON d.id = t.driver_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM ticket_returns r
            WHERE r.trip_ticket_id = t.id
              AND r.reversed_at IS NULL
        )
    ";

    $params = [];

    if ($filters['plate'] !== '') {
        $sql .= ' AND v.plate_number LIKE :plate';
        $params['plate'] = '%' .
            str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $filters['plate']
            ) . '%';
        $sql .= " ESCAPE '!' ";
    }

    if ($filters['date_from'] !== '') {
        $sql .= ' AND t.transaction_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }

    if ($filters['date_to'] !== '') {
        $sql .= ' AND t.transaction_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }

    $sql .= ' ORDER BY t.transaction_date DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function reportQueryString(array $filters): string
{
    return http_build_query(array_filter(
        $filters,
        static fn($value) => $value !== ''
    ));
}

function safeCsvCell($value): string
{
    $text = (string) $value;

    // Prevent spreadsheet software from interpreting
    // untrusted values as formulas.
    if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text)) {
        return "'" . $text;
    }

    return $text;
}
