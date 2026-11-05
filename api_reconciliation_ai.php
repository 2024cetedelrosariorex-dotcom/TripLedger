<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/report_functions.php';
require_once __DIR__ . '/../app/gemini.php';

requireStaff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function analysisResponse(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    analysisResponse(405, [
        'success' => false,
        'message' => 'POST requests only.'
    ]);
}

if (
    isset($_SERVER['CONTENT_LENGTH']) &&
    (int) $_SERVER['CONTENT_LENGTH'] > 4096
) {
    analysisResponse(413, [
        'success' => false,
        'message' => 'Request is too large.'
    ]);
}

$raw = file_get_contents('php://input', false, null, 0, 4097);

if (!is_string($raw) || strlen($raw) > 4096) {
    analysisResponse(413, [
        'success' => false,
        'message' => 'Invalid request size.'
    ]);
}

$contentType = strtolower(trim(
    explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]
));

if ($contentType !== 'application/json') {
    analysisResponse(415, [
        'success' => false,
        'message' => 'JSON content type required.'
    ]);
}

$input = json_decode($raw, true);

if (!is_array($input)) {
    analysisResponse(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

$token = $input['csrf_token'] ?? null;

if (
    !is_string($token) ||
    !hash_equals(csrfToken(), $token)
) {
    analysisResponse(403, [
        'success' => false,
        'message' => 'Invalid security token. Refresh the page.'
    ]);
}

// Basic session-based AI usage limit.
$now = time();

if (
    !isset($_SESSION['reconciliation_ai_times']) ||
    !is_array($_SESSION['reconciliation_ai_times'])
) {
    $_SESSION['reconciliation_ai_times'] = [];
}

$_SESSION['reconciliation_ai_times'] = array_values(
    array_filter(
        $_SESSION['reconciliation_ai_times'],
        static fn($time): bool =>
            is_int($time) && $time > $now - 3600
    )
);

if (count($_SESSION['reconciliation_ai_times']) >= 20) {
    analysisResponse(429, [
        'success' => false,
        'message' => 'Hourly AI analysis limit reached.'
    ]);
}

$lastTime = end($_SESSION['reconciliation_ai_times']);

if ($lastTime !== false && $now - $lastTime < 5) {
    analysisResponse(429, [
        'success' => false,
        'message' => 'Please wait before generating another analysis.'
    ]);
}

try {
    // Reuse the exact report filtering and retrieval
    // functions already used by outstanding.php.
    $filterInput = [];

    foreach (['plate', 'date_from', 'date_to'] as $key) {
        $value = $input[$key] ?? '';

        if (!is_string($value)) {
            analysisResponse(422, [
                'success' => false,
                'message' => 'Invalid report filter.'
            ]);
        }

        $filterInput[$key] = $value;
    }

    $filters = reportFilters($filterInput);
    $records = outstandingRecords(db(), $filters);

    $totalOutstanding = count($records);

    // Age is measured from transaction date.
    // This is NOT an official overdue classification.
    $today = new DateTimeImmutable(
        'today',
        new DateTimeZone('Asia/Manila')
    );

    $ageBuckets = [
        '0_to_7_days' => 0,
        '8_to_30_days' => 0,
        '31_plus_days' => 0,
        'future_dates' => 0
    ];

    foreach ($records as $record) {
        $rawDate = $record['transaction_date'] ?? null;

        if (!is_string($rawDate)) {
            throw new RuntimeException(
                'A report record has no valid transaction date.'
            );
        }

        $transactionDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $rawDate,
            new DateTimeZone('Asia/Manila')
        );

        if (
            $transactionDate === false ||
            $transactionDate->format('Y-m-d') !== $rawDate
        ) {
            throw new RuntimeException(
                'Invalid transaction date in report data.'
            );
        }

        if ($transactionDate > $today) {
            $ageBuckets['future_dates']++;
            continue;
        }

        $days = (int) $transactionDate
            ->diff($today)
            ->days;

        if ($days <= 7) {
            $ageBuckets['0_to_7_days']++;
        } elseif ($days <= 30) {
            $ageBuckets['8_to_30_days']++;
        } else {
            $ageBuckets['31_plus_days']++;
        }
    }

    $summary = [
        'total_outstanding' => $totalOutstanding,
        'age_groups' => $ageBuckets,
        'age_basis' => 'Days since transaction date'
    ];

    // Count the attempt before contacting Gemini.
    $_SESSION['reconciliation_ai_times'][] = $now;

    // Release PHP session lock while Gemini processes.
    session_write_close();

    if ($totalOutstanding === 0) {
        analysisResponse(200, [
            'success' => true,
            'summary' => $summary,
            'analysis' =>
                'No tickets without active recorded returns ' .
                'were found for the selected filters. ' .
                'Continue regular record verification. ' .
                'This result reflects encoded records only.',
            'source' => 'database'
        ]);
    }

    // ONLY aggregate numeric statistics are sent.
    // No ticket number, plate, driver, or record details.
    $aggregateJson = json_encode(
        $summary,
        JSON_THROW_ON_ERROR
    );

$prompt =
    "You are an AI reconciliation decision-support assistant "
    . "for a fictional government trip-ticket system.\n\n"

    . "VERIFIED AGGREGATE DATA:\n"
    . $aggregateJson . "\n\n"

    . "Create a SHORT, COMPLETE report using exactly "
    . "these three plain-text headings:\n\n"

    . "OVERVIEW\n"
    . "KEY FINDINGS\n"
    . "RECOMMENDED ACTIONS\n\n"

    . "Requirements:\n"
    . "1. Use only the supplied aggregate figures.\n"
    . "2. Explain the outstanding ticket count.\n"
    . "3. Describe age groups if relevant.\n"
    . "4. Recommend two practical verification actions.\n"
    . "5. Never invent records, names, or dates.\n"
    . "6. Do not classify tickets as lost or overdue.\n"
    . "7. Staff must verify physical documents.\n"
    . "8. Do not make administrative decisions.\n"
    . "9. Maximum 120 words total.\n"
    . "10. Do not use Markdown symbols such as ** or #.\n"
    . "11. Finish every sentence completely.\n";


try {
    $analysis = tripledgerGeminiGenerateWithRetry(
        $prompt,
        4096,
        'analysis'
    );

    $analysisSource = 'gemini';

} catch (RuntimeException $aiError) {

    error_log(
        'Gemini unavailable; database fallback used: '
        . $aiError->getMessage()
    );

    $ages = $summary['age_groups'];

    $analysis =
        "OVERVIEW\n"
        . "The selected report contains "
        . $totalOutstanding
        . " trip ticket(s) without an active recorded return.\n\n"

        . "KEY FINDINGS\n"
        . "0-7 days: "
        . $ages['0_to_7_days'] . "\n"
        . "8-30 days: "
        . $ages['8_to_30_days'] . "\n"
        . "31+ days: "
        . $ages['31_plus_days'] . "\n"
        . "Future-dated records: "
        . $ages['future_dates'] . "\n\n"

        . "RECOMMENDED ACTIONS\n"
        . "1. Review older outstanding records and verify "
        . "their physical return status.\n"
        . "2. Check the corresponding paper logbook "
        . "before updating any return information.\n\n"

        . "These records are not automatically "
        . "considered lost or overdue.";

    $analysisSource = 'database_fallback';
}


    analysisResponse(200, [
        'success' => true,
        'summary' => $summary,
        'analysis' => $analysis,
       'source' => $analysisSource
    ]);

} catch (InvalidArgumentException $e) {
    analysisResponse(422, [
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log(
        'TripLedger reconciliation AI error: ' .
        $e->getMessage()
    );

    analysisResponse(503, [
        'success' => false,
        'message' =>
            'Unable to generate the AI analysis. ' .
            'The original outstanding report remains available.'
    ]);
}
