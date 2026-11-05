<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/report_functions.php';

requireLogin();

$user = currentUser();

if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

try {
    $filters = reportFilters($_GET);
    $records = outstandingRecords(db(), $filters);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit('Invalid report filters.');
} catch (Throwable $e) {
    error_log('CSV export error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to generate CSV report.');
}

$filename = 'TripLedger_Outstanding_' .
    date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'w');

if ($output === false) {
    exit;
}

// UTF-8 BOM for Microsoft Excel compatibility.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Ticket Number',
    'Transaction Date',
    'Plate Number',
    "Driver's Name"
]);

foreach ($records as $record) {
    fputcsv($output, [
        safeCsvCell($record['ticket_number']),
        safeCsvCell($record['transaction_date']),
        safeCsvCell($record['plate_number']),
        safeCsvCell($record['driver_name'])
    ]);
}

fclose($output);
exit;
