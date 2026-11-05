<?php

require_once __DIR__ . '/../app/auth.php';

requireLogin();

$user = currentUser();

if (!$user) {
    header('Location: index.php');
    exit;
}

// Dashboard totals for all recorded transaction dates.
$stmt = db()->query(
    "SELECT
        COUNT(*) AS total_issued,
        COALESCE(SUM(
            CASE WHEN EXISTS (
                SELECT 1
                FROM ticket_returns r
                WHERE r.trip_ticket_id = t.id
                  AND r.reversed_at IS NULL
            )
            THEN 1 ELSE 0 END
        ), 0) AS total_returned
     FROM trip_tickets t"
);

$stats = $stmt->fetch();

$issued = (int) $stats['total_issued'];
$returned = (int) $stats['total_returned'];
$outstanding = $issued - $returned;

// Recent issued tickets, with status derived from active returns.
$recentStmt = db()->query(
    "SELECT
        t.id,
        t.ticket_number,
        t.transaction_date,
        v.plate_number,
        d.full_name AS driver_name,
        CASE WHEN EXISTS (
            SELECT 1
            FROM ticket_returns r
            WHERE r.trip_ticket_id = t.id
              AND r.reversed_at IS NULL
        )
        THEN 1 ELSE 0 END AS is_returned
     FROM trip_tickets t
     JOIN vehicles v ON v.id = t.vehicle_id
     JOIN drivers d ON d.id = t.driver_id
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 5"
);

$recentTickets = $recentStmt->fetchAll();

$pageTitle = 'Dashboard';

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

    <title>Dashboard | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>
    <link rel="stylesheet" href="assets/trippy.css">
<script src="assets/trippy.js" defer></script>
</head>

<body>

<div class="app-layout">

    <?php require __DIR__ . '/../app/partials/sidebar.php'; ?>

    <div class="tl-content">

        <?php require __DIR__ . '/../app/partials/header.php'; ?>

        <main class="tl-dashboard-body">

            <section class="tl-welcome">
                <h2>
                    Welcome, <?= e($user['full_name']) ?>!
                </h2>

                <p>
                    Trip Ticket Records Overview
                    — Department of Agrarian Reform,
                    Sarangani.
                </p>

                <p>
                    <strong>Reporting Period:</strong>
                    All recorded transaction dates
                </p>
            </section>

            <section class="tl-stats" aria-label="Ticket statistics">

                <div class="tl-stat-card">
                    <p class="tl-stat-label">
                        Total Issued Tickets
                    </p>

                    <div class="tl-stat-number">
                        <?= number_format($issued) ?>
                    </div>
                </div>

                <div class="tl-stat-card">
                    <p class="tl-stat-label">
                        Returned Tickets
                    </p>

                    <div class="tl-stat-number">
                        <?= number_format($returned) ?>
                    </div>
                </div>

                <div class="tl-stat-card">
                    <p class="tl-stat-label">
                        Not Yet Recorded as Returned
                    </p>

                    <div class="tl-stat-number">
                        <?= number_format($outstanding) ?>
                    </div>
                </div>

            </section>

            <section aria-labelledby="quick-actions-title">
                <h2 class="tl-section-heading" id="quick-actions-title">
                    Quick Actions
                </h2>

                <div class="tl-quick-actions">

                    <a
                        href="issuance.php"
                        class="app-button app-button-primary"
                    >
                        + Record New Issuance
                    </a>

                    <a
                        href="records.php"
                        class="app-button app-button-secondary"
                    >
                        Search Trip Tickets
                    </a>

                    <a
                        href="outstanding.php"
                        class="app-button app-button-secondary"
                    >
                        Outstanding Report
                    </a>

                </div>
            </section>

            <section class="app-card" aria-labelledby="recent-title">

                <h2 class="tl-section-heading" id="recent-title">
                    Recent Trip Tickets
                </h2>

                <?php if (empty($recentTickets)): ?>

                    <div class="app-empty-state">
                        <h3>No trip ticket records yet</h3>

                        <p>
                            Record your first issuance to display
                            recent trip tickets here.
                        </p>

                        <p style="margin-top:15px;">
                            <a
                                href="issuance.php"
                                class="app-button app-button-primary"
                            >
                                Record New Issuance
                            </a>
                        </p>
                    </div>

                <?php else: ?>

                    <div class="app-table-container">

                        <table class="app-table tl-recent-table">
                            <thead>
                                <tr>
                                    <th>Ticket Number</th>
                                    <th>Transaction Date</th>
                                    <th>Plate Number</th>
                                    <th>Driver</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($recentTickets as $ticket): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= e($ticket['ticket_number']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($ticket['transaction_date']) ?>
                                        </td>

                                        <td>
                                            <?= e($ticket['plate_number']) ?>
                                        </td>

                                        <td>
                                            <?= e($ticket['driver_name']) ?>
                                        </td>

                                        <td>
                                            <?php if ((int) $ticket['is_returned'] === 1): ?>
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
                                                href="ticket_details.php?id=<?= (int) $ticket['id'] ?>"
                                                style="color:#155e45;font-weight:600;"
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

        </main>

    </div>

</div>
<?php require __DIR__ . '/../app/partials/trippy_widget.php'; ?>
</body>
</html>
