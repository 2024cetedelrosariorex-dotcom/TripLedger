<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/report_functions.php';

requireLogin();

$user = currentUser();

if (!$user) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Outstanding Tickets Report';

$error = '';
$records = [];
$filters = [
    'plate' => '',
    'date_from' => '',
    'date_to' => ''
];

try {
    $filters = reportFilters($_GET);
    $records = outstandingRecords(db(), $filters);
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Outstanding report error: ' . $e->getMessage());
    $error = 'Unable to load the outstanding tickets report.';
}

$total = count($records);

$query = reportQueryString($filters);

$exportUrl = 'export_outstanding.php' .
    ($query !== '' ? '?' . $query : '');

$period = 'All recorded transaction dates';

if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
    $period = ($filters['date_from'] ?: 'Beginning') .
        ' to ' .
        ($filters['date_to'] ?: 'Latest');
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

    <title>Outstanding Tickets Report | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">

    <script src="assets/app.js" defer></script>

    
<meta
    name="csrf-token"
    content="<?= e(csrfToken()) ?>"
>

<link
    rel="stylesheet"
    href="assets/reconciliation-ai.css"
>

<script
    src="assets/reconciliation-ai.js"
    defer
></script>

</head>

<body>

<div class="app-layout">

    <?php require __DIR__ . '/../app/partials/sidebar.php'; ?>

    <div class="tl-content">

        <?php require __DIR__ . '/../app/partials/header.php'; ?>

        <main class="tl-dashboard-body tl-report-main">

            <header class="tl-report-intro no-print">
                <h2>Outstanding Tickets Report</h2>

                <p>
                    View issued trip tickets without an active
                    recorded physical return.
                </p>

                <p>
                    An outstanding ticket does not necessarily
                    mean the physical document is lost.
                </p>
            </header>

            <div class="tl-print-heading">
                <h2>Department of Agrarian Reform – Sarangani</h2>
                <p>General Services and Procurement Office</p>
                <h3>Outstanding Trip Tickets Report</h3>
            </div>

            <?php if ($error !== ''): ?>
                <div class="tl-issuance-alert tl-alert-error no-print"
                     role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="app-card no-print">

                <h2 class="tl-section-heading">
                    Report Filters
                </h2>

                <form method="GET" action="outstanding.php">

                    <div class="tl-report-filters">

                        <div class="tl-filter-field">
                            <label for="plate">
                                Vehicle Plate Number
                            </label>

                            <input
                                type="text"
                                id="plate"
                                name="plate"
                                maxlength="30"
                                placeholder="Example: ABC 1234"
                                value="<?= e($filters['plate']) ?>"
                            >
                        </div>

                        <div class="tl-filter-field">
                            <label for="date_from">
                                Date From
                            </label>

                            <input
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= e($filters['date_from']) ?>"
                            >
                        </div>

                        <div class="tl-filter-field">
                            <label for="date_to">
                                Date To
                            </label>

                            <input
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= e($filters['date_to']) ?>"
                            >
                        </div>

                    </div>

                    <div class="tl-record-actions">

                        <button
                            type="submit"
                            class="app-button app-button-primary"
                        >
                            Generate Report
                        </button>

                        <a
                            href="outstanding.php"
                            class="app-button app-button-secondary"
                        >
                            Clear Filters
                        </a>

                    </div>

                </form>

            </section>

            <?php if ($error === ''): ?>

                <section class="app-card tl-report-results">

                    <div class="tl-report-summary">

                        <div>
                            <p class="tl-report-summary-label">
                                Tickets without Recorded Returns
                            </p>

                            <div class="tl-report-total">
                                <?= number_format($total) ?>
                            </div>

                            <p class="tl-report-meta">
                                Transaction Period:
                                <?= e($period) ?>
                            </p>

                            <?php if ($filters['plate'] !== ''): ?>
                                <p class="tl-report-meta">
                                    Plate Number Filter:
                                    <?= e($filters['plate']) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="tl-report-actions no-print">

                        
<button
    type="button"
    id="generateAiAnalysis"
    class="app-button app-button-primary"
>
    Generate AI Analysis
</button>


                            <button
                                type="button"
                                class="app-button app-button-primary"
                                onclick="window.print()"
                            >
                                Print Report
                            </button>

                            <a
                                href="<?= e($exportUrl) ?>"
                                class="app-button app-button-secondary"
                            >
                                Export CSV
                            </a>

                        </div>

                    </div>

                    <?php if (empty($records)): ?>

                        <div class="app-empty-state">
                            <div class="tl-empty-icon"
                                 aria-hidden="true">
                                &#128203;
                            </div>

                            <h3>No Outstanding Tickets Found</h3>

                            <p>
                                There are no issued trip ticket
                                without active recorded returns
                                for the selected filters.
                            </p>

                            <a
                                href="outstanding.php"
                                class="app-button app-button-secondary no-print"
                            >
                                Clear Filters
                            </a>
                        </div>

                    <?php else: ?>

                        <div class="app-table-container tl-report-table-wrap">

                            <table class="app-table tl-report-table">

                                <thead>
                                    <tr>
                                        <th>Ticket Number</th>
                                        <th>Transaction Date</th>
                                        <th>Vehicle Plate Number</th>
                                        <th>Driver's Name</th>
                                        <th class="no-print">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($records as $record): ?>

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

                                            <td class="no-print">
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

                    <div class="tl-report-footer">
                        <p>
                            Generated on
                            <?= e(date('F j, Y g:i A')) ?>
                            (Asia/Manila)
                        </p>

                        <p>
                            Source: TripLedger recorded
                            trip-ticket transactions and returns.
                        </p>
                    </div>

                </section>


                   
<section
    id="aiAnalysisPanel"
    class="ai-analysis-panel no-print"
    aria-labelledby="aiAnalysisTitle"
    hidden
>
    <div class="ai-analysis-heading">
        <h2 id="aiAnalysisTitle">
            AI Reconciliation Report Analyst
        </h2>

        <p>
            AI-assisted insights and review recommendations
            based on verified TripLedger report statistics.
        </p>
    </div>

    <div class="ai-analysis-body">

        <div
            id="aiAnalysisStatus"
            class="ai-analysis-status"
            role="status"
            aria-live="polite"
        ></div>

        <div class="ai-analysis-summary">
            <div
                id="aiOutstandingCount"
                class="ai-analysis-count"
            >
                —
            </div>

            <p>
                Tickets without active recorded returns
                in the selected report.
            </p>
        </div>

        <div
            id="aiAnalysisResults"
            class="ai-analysis-results"
        ></div>

        <p class="ai-analysis-note">
            This analysis supports administrative review only.
            The Authorized Staff remains responsible for
            verifying the physical trip ticket and its
            recorded return status. AI recommendations
            do not modify TripLedger records.
        </p>

    </div>
</section>



            <?php endif; ?>

        </main>

    </div>

</div>

</body>
</html>
