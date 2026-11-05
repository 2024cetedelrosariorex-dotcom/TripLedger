
<?php

// ==========================================
// TRIPLEDGER CHATBOT SERVICE
// SMART RECORD RETRIEVAL
// ==========================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/ticket_functions.php';

// ==========================================
// SUPPORTED EXAMPLES
// ==========================================

function trippyExamples(): string
{
    return "I use supported phrases and validated filters for local database lookup. Please use one of these examples:\n"
        . "- Show outstanding tickets\n"
        . "- Find tickets for plate ABC 1234\n"
        . "- Find ticket 2026-10-0001\n"
        . "- Show outstanding tickets for October 2026\n"
        . "- How many tickets were returned in October 2026?\n"
        . "- What is ticket reconciliation?\n"
        . "- What does outstanding mean?\n"
        . "- What is a trip ticket?\n"
        . "You can also use: from 2026-10-01 to 2026-10-31. Include a year with each month.\n"
        . "Issued/outstanding periods use transaction dates; returned periods use active return dates.";
}

// ==========================================
// PARSE LOCAL SEARCH REQUEST
// ==========================================

function trippyParseQuery(string $message): ?array
{
    $query = strtolower(trim(
        preg_replace('/\s+/', ' ', $message)
    ));

    $query = rtrim($query, "?.! ");
    $period = null;

    if (preg_match(
        '/\s+from (\d{4}-\d{2}-\d{2}) to (\d{4}-\d{2}-\d{2})$/',
        $query,
        $m
    )) {
        validateTripLedgerDate($m[1]);
        validateTripLedgerDate($m[2]);

        if ($m[1] > $m[2]) {
            throw new InvalidArgumentException(
                'The start date must not follow the end date.'
            );
        }

        $period = [
            'from' => $m[1],
            'to' => $m[2]
        ];

        $query = substr($query, 0, -strlen($m[0]));

    } elseif (preg_match(
        '/\s+(?:for|in|during) ([a-z]+) (\d{4})$/',
        $query,
        $m
    )) {
        $months = array_flip([
            'january', 'february', 'march',
            'april', 'may', 'june',
            'july', 'august', 'september',
            'october', 'november', 'december'
        ]);

        if (
            !isset($months[$m[1]]) ||
            (int) $m[2] < 1000
        ) {
            throw new InvalidArgumentException(
                'Specify a full month name and a four-digit year.'
            );
        }

        $from = sprintf(
            '%04d-%02d-01',
            (int) $m[2],
            $months[$m[1]] + 1
        );

        $period = [
            'from' => $from,
            'to' => validateTripLedgerDate($from)
                ->format('Y-m-t')
        ];

        $query = substr($query, 0, -strlen($m[0]));
    }

    $plate = null;
    $ticket = null;
    $status = '';
    $count = false;

    if (preg_match(
        '/^(?:find|show) (?:trip )?ticket (\d{4}-(?:0[1-9]|1[0-2])-\d{4,9})$/',
        $query,
        $m
    )) {
        $width = (int) (
            tripledgerConfig()['ticket_numbering']['sequence_width']
            ?? 4
        );

        if (strlen(explode('-', $m[1])[2]) !== $width) {
            throw new InvalidArgumentException(
                'Ticket sequence width does not match system configuration.'
            );
        }

        $ticket = $m[1];

    } else {
        if (preg_match(
            '/\s+for plate(?: number)? ([a-z0-9 -]+)$/',
            $query,
            $m
        )) {
            $plate = normalizeTripLedgerPlate($m[1]);
            validateTripLedgerPlate($plate);

            $plate = str_replace(
                [' ', '-'],
                '',
                $plate
            );

            $query = substr($query, 0, -strlen($m[0]));
        }

        if (preg_match(
            '/^(?:show|list|find) (?:all )?(?:(outstanding|returned|issued) )?(?:trip )?tickets$/',
            $query,
            $m
        )) {
            $status = $m[1] ?? '';

        } elseif (preg_match(
            '/^how many (?:(outstanding|returned|issued) )?(?:trip )?tickets(?: (?:were |are |have been )?(outstanding|returned|issued))?$/',
            $query,
            $m
        )) {
            if (!empty($m[1]) && !empty($m[2])) {
                return null;
            }

            $status = $m[1] ?: ($m[2] ?? '');
            $count = true;

        } else {
            // Do not ignore unsupported filters.
            return null;
        }
    }

    return [
        'plate' => $plate,
        'ticket' => $ticket,
        'status' => $status === 'issued' ? '' : $status,
        'period' => $period,
        'count' => $count
    ];
}

// ==========================================
// CHATBOT RESPONSE
// ==========================================

function tripledgerChatbotReply(string $message): array
{
    $message = trim($message);

    if ($message === '' || strlen($message) > 500) {
        return [
            'reply' => trippyExamples(),
            'source' => 'system'
        ];
    }

    $question = strtolower(
        trim(preg_replace('/\s+/', ' ', $message))
    );

    $question = rtrim($question, "?.! ");

    // ======================================
    // LOCAL KNOWLEDGE BASE
    // ======================================
    // These explanations work even when
    // Gemini is disabled or unavailable.

    $localExplanations = [
        'what is ticket reconciliation' =>
            'Ticket reconciliation is the process of comparing issued trip tickets with their recorded physical returns. '
            . 'In TripLedger, it helps Authorized Staff identify which tickets have been returned and which remain outstanding. '
            . 'An outstanding ticket does not automatically mean it is lost or overdue.',

        'what does outstanding mean' =>
            'An outstanding ticket is an issued trip ticket without an active recorded physical return in TripLedger. '
            . 'Staff should verify the paper records before determining why it remains outstanding.',

        'what is a trip ticket' =>
            'A trip ticket is a record used to document vehicle-related trip transactions. '
            . 'In TripLedger, each issued ticket has a unique ticket number and stores its transaction date, '
            . 'vehicle plate number, driver name, and return information.'
    ];

    if (isset($localExplanations[$question])) {
        return [
            'reply' => $localExplanations[$question],
            'source' => 'system'
        ];
    }

    // ======================================
    // DATABASE STATISTICS
    // ======================================

    if (in_array(
        $question,
        [
            'show ticket statistics',
            'ticket statistics',
            'show statistics'
        ],
        true
    )) {
        $stats = trippyStatistics();

        return [
            'reply' =>
                "Reporting period: All transaction dates.\n"
                . "Issued: {$stats['issued']}\n"
                . "Returned: {$stats['returned']}\n"
                . "Outstanding: {$stats['outstanding']}\n"
                . "Outstanding does not mean lost or overdue.",
            'source' => 'database'
        ];
    }

    // ======================================
    // VALIDATED RECORD RETRIEVAL
    // ======================================

    try {
        $filters = trippyParseQuery($message);
    } catch (InvalidArgumentException $e) {
        return [
            'reply' => $e->getMessage()
                . "\n\n"
                . trippyExamples(),
            'source' => 'system'
        ];
    }

    if ($filters === null) {
        return [
            'reply' => trippyExamples(),
            'source' => 'system'
        ];
    }

    $result = trippySearchRecords(
        $filters['plate'],
        $filters['ticket'],
        $filters['status'],
        $filters['period']
    );

    $basis = $filters['status'] === 'returned'
        ? 'active return_date'
        : 'transaction_date';

    $period = $filters['period'];

    $description = $period
        ? $period['from'] . ' to ' . $period['to']
        : 'All dates';

    $reply =
        "Local database lookup\n"
        . "Applied period: $description ($basis).\n"
        . 'Status: '
        . ($filters['status'] ?: 'All issued')
        . '. ';

    if ($filters['plate']) {
        $reply .= 'Plate: ' . $filters['plate'] . '. ';
    }

    if ($filters['ticket']) {
        $reply .= 'Ticket: ' . $filters['ticket'] . '. ';
    }

    $reply .= "\n" . (
        $filters['count']
            ? 'Matching records: ' . $result['total']
            : trippyFormatRecords($result)
    );

    if ($filters['status'] === 'outstanding') {
        $reply .=
            "\nOutstanding does not automatically mean lost or overdue.";
    }

    return [
        'reply' => $reply,
        'source' => 'database'
    ];
}

// ==========================================
// DATABASE STATISTICS
// ==========================================

function trippyStatistics(): array
{
    $stmt = db()->query(
        "SELECT
            COUNT(*) AS issued,
            COALESCE(
                SUM(
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM ticket_returns r
                        WHERE r.trip_ticket_id = t.id
                          AND r.reversed_at IS NULL
                    )
                    THEN 1 ELSE 0 END
                ),
                0
            ) AS returned
         FROM trip_tickets t"
    );

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $issued = (int) ($result['issued'] ?? 0);
    $returned = (int) ($result['returned'] ?? 0);

    return [
        'issued' => $issued,
        'returned' => $returned,
        'outstanding' => $issued - $returned
    ];
}

// ==========================================
// EXTRACT PLATE NUMBER
// ==========================================

function trippyExtractPlate(string $question): ?string
{
    if (preg_match(
        '/\b(?:plate(?:\s+number)?|plaka)\s*'
        . '(?:no\.?\s*|number\s*|#\s*|:\s*)?'
        . '([A-Z]{2,4}[\s-]?\d{3,5}|\d{3,4}[\s-]?\d{3,5})\b/i',
        $question,
        $matches
    )) {
        return strtoupper(
            preg_replace('/[\s-]+/', '', $matches[1])
        );
    }

    return null;
}

// ==========================================
// EXTRACT TICKET NUMBER
// ==========================================

function trippyExtractTicketNumber(
    string $question
): ?string {
    if (preg_match(
        '/\b(?:20\d{2}|\d{2})-\d{2}-\d{4}\b/',
        $question,
        $matches
    )) {
        return $matches[0];
    }

    return null;
}

// ==========================================
// RETRIEVE TICKET RECORDS
// ==========================================

function trippySearchRecords(
    ?string $plate,
    ?string $ticketNumber,
    string $status,
    ?array $period = null
): array {
    $where = [];
    $params = [];

    if ($plate !== null) {
        $where[] =
            "REPLACE(
                REPLACE(UPPER(v.plate_number), ' ', ''),
                '-', ''
            ) = :plate";

        $params['plate'] = $plate;
    }

    if ($ticketNumber !== null) {
        $where[] = 't.ticket_number = :ticket_number';
        $params['ticket_number'] = $ticketNumber;
    }

    if ($status === 'returned') {
        $where[] = 'r.id IS NOT NULL';

    } elseif ($status === 'outstanding') {
        $where[] = 'r.id IS NULL';
    }

    if ($period !== null) {
        $column = $status === 'returned'
            ? 'r.return_date'
            : 't.transaction_date';

        $where[] =
            "$column >= :period_from AND $column <= :period_to";

        $params['period_from'] = $period['from'];
        $params['period_to'] = $period['to'];
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    $fromSql = "
        FROM trip_tickets t
        INNER JOIN vehicles v
            ON v.id = t.vehicle_id
        INNER JOIN drivers d
            ON d.id = t.driver_id
        LEFT JOIN ticket_returns r
            ON r.trip_ticket_id = t.id
           AND r.reversed_at IS NULL
        $whereSql
    ";

    $countStmt = db()->prepare(
        "SELECT COUNT(*) $fromSql"
    );

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT
            t.id,
            t.ticket_number,
            t.transaction_date,
            v.plate_number,
            d.full_name AS driver_name,
            r.return_date,
            CASE
                WHEN r.id IS NOT NULL
                THEN 'Returned'
                ELSE 'Not Yet Recorded as Returned'
            END AS return_status
         $fromSql
         ORDER BY t.transaction_date DESC, t.id DESC
         LIMIT 10"
    );

    $stmt->execute($params);

    return [
        'total' => $total,
        'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ];
}

// ==========================================
// FORMAT RECORD SEARCH RESULTS
// ==========================================

function trippyFormatRecords(array $result): string
{
    $total = $result['total'];
    $records = $result['records'];

    if ($total === 0) {
        return
            "Matching records: 0. "
            . "No matching trip ticket records were found.\n\n"
            . "Try checking the ticket number, vehicle plate "
            . "number, or return status.";
    }

    $reply = "TripLedger Record Search\n\n";

    $reply .=
        "Matching records: "
        . number_format($total)
        . "\n";

    $reply .=
        "Showing: "
        . count($records)
        . " record(s)\n\n";

    foreach ($records as $index => $record) {
        $reply .=
            ($index + 1)
            . ". Ticket: "
            . $record['ticket_number']
            . "\n";

        $reply .=
            "Plate: "
            . $record['plate_number']
            . "\n";

        $reply .=
            "Driver: "
            . $record['driver_name']
            . "\n";

        $reply .=
            "Transaction Date: "
            . $record['transaction_date']
            . "\n";

        $reply .=
            "Return Date: "
            . ($record['return_date'] ?? 'Not recorded')
            . "\n";

        $reply .=
            "Status: "
            . $record['return_status']
            . "\n";

        $reply .=
            "Record ID: "
            . (int) $record['id']
            . "\n\n";
    }

    if ($total > count($records)) {
        $reply .=
            "Additional matches are available "
            . "in the Trip Ticket Records page.\n\n";
    }

    $reply .=
        "All results are based on recorded "
        . "database transactions.";

    return $reply;
}
