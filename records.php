<?php

require_once __DIR__ . '/../app/auth.php';

requireLogin();

$user = currentUser();

if (!$user) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Trip Ticket Records';

function recordInput(string $key): string {
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
$plate = recordInput('plate');
$ticketNumber = recordInput('ticket');
$dateFrom = recordInput('date_from');
$dateTo = recordInput('date_to');
$status = recordInput('status');
$page = max(1, (int) recordInput('page'));
$pageSize = 25;
$totalPages = 1;

$error = '';
$records = [];
$totalResults = 0;

function validRecordDate(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false &&
        $date->format('Y-m-d') === $value;
}

if (strlen($ticketNumber) > 40) {
    $error = 'Ticket number must not exceed 40 characters.';
} elseif (count(array_filter($_GET, 'is_array')) > 0) {
    $error = 'Invalid filter format.';
} elseif (strlen($plate) > 30) {
    $error = 'Plate number must not exceed 30 characters.';
} elseif (
    !validRecordDate($dateFrom) ||
    !validRecordDate($dateTo)
) {
    $error = 'Please enter valid dates.';
} elseif (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    $dateFrom > $dateTo
) {
    $error = 'Date From cannot be later than Date To.';
} elseif (
    !in_array($status, ['', 'returned', 'outstanding'], true)
) {
    $error = 'Invalid status filter.';
} else {
    $where = [];
    $params = [];

    if ($plate !== '') {
        // Escape LIKE wildcard characters so plate input
        // is interpreted as text rather than a pattern.
        $escapedPlate = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $plate
        );

        $where[] = "v.plate_number LIKE :plate ESCAPE '!'";
        $params['plate'] = '%' . $escapedPlate . '%';
    }

    if ($ticketNumber !== '') {
        $where[] = "t.ticket_number LIKE :ticket ESCAPE '!'";
        $params['ticket'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $ticketNumber) . '%';
    }

    if ($dateFrom !== '') {
        $where[] = 't.transaction_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 't.transaction_date <= :date_to';
        $params['date_to'] = $dateTo;
    }

    if ($status === 'returned') {
        $where[] = 'r.id IS NOT NULL';
    } elseif ($status === 'outstanding') {
        $where[] = 'r.id IS NULL';
    }

    $filterSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    try {
        // Count all matching records, not just displayed rows.
        $countSql = "
            SELECT COUNT(*)
            FROM trip_tickets t
            INNER JOIN vehicles v ON v.id = t.vehicle_id
            INNER JOIN drivers d ON d.id = t.driver_id
            LEFT JOIN ticket_returns r
                ON r.trip_ticket_id = t.id
                AND r.reversed_at IS NULL
            $filterSql
        ";

        $countStmt = db()->prepare($countSql);
        $countStmt->execute($params);
        $totalResults = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalResults / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $sql = "
            SELECT
                t.id,
                t.ticket_number,
                t.transaction_date,
                v.plate_number,
                d.full_name AS driver_name,
                r.return_date,
                CASE
                    WHEN r.id IS NOT NULL THEN 'Returned'
                    ELSE 'Not Yet Recorded as Returned'
                END AS return_status
            FROM trip_tickets t
            INNER JOIN vehicles v ON v.id = t.vehicle_id
            INNER JOIN drivers d ON d.id = t.driver_id
            LEFT JOIN ticket_returns r
                ON r.trip_ticket_id = t.id
                AND r.reversed_at IS NULL
            $filterSql
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT $pageSize OFFSET $offset
        ";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

    } catch (Throwable $e) {
        error_log('TripLedger records error: ' . $e->getMessage());
        $error = 'Unable to retrieve trip ticket records.';
        $records = [];
        $totalResults = 0;
    }
}

$displayedCount = count($records);

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

    <title>Trip Ticket Records | TripLedger</title>

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

                <h2>Trip Ticket Records</h2>

                <p>
                    Search and retrieve issued trip tickets using
                    the vehicle plate number, transaction date,
                    and recorded return status.
                </p>

                <p>
                    <strong>Reporting Period:</strong>
                    <?= e(($dateFrom ?: 'Beginning') . ' to ' . ($dateTo ?: 'Latest')) ?> (transaction dates).
                </p>

            </section>

            <?php if ($error !== ''): ?>
                <div class="app-card tl-record-error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="app-card">

                <h2 class="tl-section-heading">
                    Search and Filter Records
                </h2>

                <form
                    action="records.php"
                    method="GET"
                    id="recordsFilterForm"
                >

                    <div class="tl-record-filters">
                        <div class="tl-filter-field">
                            <label for="ticket">Ticket Number</label>
                            <input type="text" id="ticket" name="ticket" maxlength="40" value="<?= e($ticketNumber) ?>" placeholder="2026-10-0001">
                        </div>

                        <div class="tl-filter-field">
                            <label for="plate">
                                Vehicle Plate Number
                            </label>

                            <input
                                type="text"
                                name="plate"
                                id="plate"
                                maxlength="30"
                                placeholder="Example: ABC 1234"
                                value="<?= e($plate) ?>"
                            >
                        </div>

                        <div class="tl-filter-field">
                            <label for="date_from">
                                Date From
                            </label>

                            <input
                                type="date"
                                name="date_from"
                                id="date_from"
                                value="<?= e($dateFrom) ?>"
                            >
                        </div>

                        <div class="tl-filter-field">
                            <label for="date_to">
                                Date To
                            </label>

                            <input
                                type="date"
                                name="date_to"
                                id="date_to"
                                value="<?= e($dateTo) ?>"
                            >
                        </div>

                        <div class="tl-filter-field">
                            <label for="status">
                                Return Status
                            </label>

                            <select name="status" id="status">

                                <option value="">
                                    All Statuses
                                </option>

                                <option value="returned"
                                    <?= $status === 'returned'
                                        ? 'selected'
                                        : '' ?>>
                                    Returned
                                </option>

                                <option value="outstanding"
                                    <?= $status === 'outstanding'
                                        ? 'selected'
                                        : '' ?>>
                                    Not Yet Recorded as Returned
                                </option>

                            </select>
                        </div>

                    </div>

                    <div class="tl-record-actions">

                        <button
                            type="submit"
                            class="app-button app-button-primary"
                        >
                            Search Records
                        </button>

                        <a
                            href="records.php"
                            class="app-button app-button-secondary"
                        >
                            Clear Filters
                        </a>

                    </div>

                </form>

            </section>

            <section class="app-card">

                <div class="tl-records-heading">

                    <div>
                        <h2 class="tl-section-heading">
                            Issued Trip Tickets
                        </h2>

                        <?php if ($error === ''): ?>
                            <p class="tl-record-count">
                                <?= number_format($totalResults) ?>
                                matching record(s)

                                <?php if ($totalResults > $displayedCount): ?>
                                    — Showing on this page:
                                    <?= number_format($displayedCount) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <a
                        href="issuance.php"
                        class="app-button app-button-primary"
                    >
                        + New Issuance
                    </a>

                </div>

                <?php if ($error === '' && empty($records)): ?>

                    <div class="app-empty-state">

                        <div class="tl-empty-icon" aria-hidden="true">
                            &#128193;
                        </div>

                        <h3>No Trip Ticket Records Found</h3>

                        <p>
                            No records match the selected filters.
                            Try another plate number, date range,
                            or return status.
                        </p>

                        <a
                            href="records.php"
                            class="app-button app-button-secondary"
                        >
                            Clear Filters
                        </a>

                    </div>

                <?php elseif ($error === ''): ?>

                    <div class="app-table-container">

                        <table class="app-table tl-records-table">

                            <thead>
                                <tr>
                                    <th>Ticket Number</th>
                                    <th>Transaction Date</th>
                                    <th>Plate Number</th>
                                    <th>Driver's Name</th>
                                    <th>Return Date</th>
                                    <th>Recorded Return Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($records as $record): ?>

                                    <?php
                                        $isReturned =
                                            $record['return_date'] !== null;
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= e($record['ticket_number']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($record['transaction_date']) ?>
                                        </td>

                                        <td>
                                            <?= e($record['plate_number']) ?>
                                        </td>

                                        <td>
                                            <?= e($record['driver_name']) ?>
                                        </td>

                                        <td>
                                            <?= $isReturned
                                                ? e($record['return_date'])
                                                : '—' ?>
                                        </td>

                                        <td>
                                            <?php if ($isReturned): ?>

                                                <span class="tl-status tl-status-returned">
                                                    Returned
                                                </span>

                                            <?php else: ?>

                                                <span class="tl-status tl-status-outstanding">
                                                    Not Yet Recorded as Returned
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <a
                                                class="tl-details-link"
                                                href="ticket_details.php?id=<?= (int) $record['id'] ?>"
                                            >
                                                View Details
                                            </a>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <?php if ($totalPages > 1): ?>
            <nav aria-label="Record pages" class="app-card">
                <?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $target): ?>
                    <?php if ($target >= 1 && $target <= $totalPages): ?>
                    <a href="records.php?<?= e(http_build_query(['plate' => $plate, 'ticket' => $ticketNumber, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $status, 'page' => $target])) ?>"><?= e($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
            </nav>
            <?php endif; ?>
        </main>

    </div>

</div>

</body>
</html>
