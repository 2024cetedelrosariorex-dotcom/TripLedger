<?php

require_once __DIR__ . '/../app/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This verification script is for terminal use only.');
}

$pdo = db();
$failed = 0;
$passed = 0;

function checkResult(string $label, bool $ok, string $details = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "[PASS] $label\n";
    } else {
        $failed++;
        echo "[FAIL] $label\n";
        if ($details !== '') {
            echo "       $details\n";
        }
    }
}

function countQuery(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

echo "========================================\n";
echo "TripLedger Database Verification\n";
echo "========================================\n\n";

try {
    $name = (string) $pdo->query(
        'SELECT DATABASE()'
    )->fetchColumn();

    checkResult(
        'Connected to configured database',
        $name === tripledgerConfig()['database']['name']
    );

    $tables = [
        'users',
        'vehicles',
        'drivers',
        'trip_tickets',
        'ticket_returns',
        'annual_counters',
        'audit_logs',
        'ai_settings',
        'schema_migrations'
    ];

    $existing = $pdo->query(
        'SHOW TABLES'
    )->fetchAll(PDO::FETCH_COLUMN);

    checkResult(
        'All nine required tables exist',
        count(array_diff($tables, $existing)) === 0
    );

    $staffCount = countQuery(
        $pdo,
        "SELECT COUNT(*) FROM users
         WHERE role = 'Authorized Staff'
         AND is_active = 1"
    );

   checkResult(
    'Three active Authorized Staff accounts',
    $staffCount === 3
);

$adminCount = countQuery(
    $pdo,
    "SELECT COUNT(*) FROM users
     WHERE role = 'Administrator'
       AND is_active = 1"
);

checkResult(
    'One active Administrator account',
    $adminCount === 1
);

    $plainPasswords = countQuery(
        $pdo,
        "SELECT COUNT(*) FROM users
         WHERE password_hash NOT LIKE '\$2y\$%'
         AND password_hash NOT LIKE '\$argon2%'"
    );

    checkResult(
        'Password hashes use recognized secure formats',
        $plainPasswords === 0
    );

    $duplicateNumbers = countQuery(
        $pdo,
        "SELECT COUNT(*) FROM (
            SELECT ticket_number
            FROM trip_tickets
            GROUP BY ticket_number
            HAVING COUNT(*) > 1
         ) x"
    );

    checkResult(
        'No duplicate ticket numbers',
        $duplicateNumbers === 0
    );

    $duplicateSequences = countQuery(
        $pdo,
        "SELECT COUNT(*) FROM (
            SELECT ticket_year, ticket_sequence
            FROM trip_tickets
            GROUP BY ticket_year, ticket_sequence
            HAVING COUNT(*) > 1
         ) x"
    );

    checkResult(
        'No duplicate annual sequences',
        $duplicateSequences === 0
    );

    $duplicateReturns = countQuery(
        $pdo,
        "SELECT COUNT(*) FROM (
            SELECT trip_ticket_id
            FROM ticket_returns
            WHERE reversed_at IS NULL
            GROUP BY trip_ticket_id
            HAVING COUNT(*) > 1
         ) x"
    );

    checkResult(
        'No ticket has multiple active returns',
        $duplicateReturns === 0
    );

    $invalidReturnDates = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM ticket_returns r
         JOIN trip_tickets t
           ON t.id = r.trip_ticket_id
         WHERE r.return_date < t.transaction_date"
    );

    checkResult(
        'Return dates are not earlier than transaction dates',
        $invalidReturnDates === 0
    );

    $totalIssued = countQuery(
        $pdo,
        'SELECT COUNT(*) FROM trip_tickets'
    );

    $totalReturned = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM trip_tickets t
         WHERE EXISTS (
             SELECT 1 FROM ticket_returns r
             WHERE r.trip_ticket_id = t.id
               AND r.reversed_at IS NULL
         )"
    );

    $totalOutstanding = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM trip_tickets t
         WHERE NOT EXISTS (
             SELECT 1 FROM ticket_returns r
             WHERE r.trip_ticket_id = t.id
               AND r.reversed_at IS NULL
         )"
    );

    checkResult(
        'Issued = Returned + Outstanding',
        $totalIssued === $totalReturned + $totalOutstanding
    );

    $missingAudit = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM trip_tickets t
         WHERE NOT EXISTS (
             SELECT 1 FROM audit_logs a
             WHERE a.trip_ticket_id = t.id
               AND a.action = 'TICKET_CREATED'
         )"
    );

    checkResult(
        'All issued tickets have creation audit entries',
        $missingAudit === 0
    );

    $missingReturnAudit = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM ticket_returns r
         WHERE NOT EXISTS (
             SELECT 1 FROM audit_logs a
             WHERE a.trip_ticket_id = r.trip_ticket_id
               AND a.action = 'RETURN_RECORDED'
               AND CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(a.new_values, '$.return_id')
                    ) AS UNSIGNED
               ) = r.id
         )"
    );

    checkResult(
        'Recorded returns have matching audit entries',
        $missingReturnAudit === 0
    );

    $configuration = require __DIR__ . '/../app/config.php';
    $width = (int) $configuration['ticket_numbering']['sequence_width'];

    $tickets = $pdo->query(
        'SELECT ticket_number, ticket_year,
                ticket_sequence, transaction_date
         FROM trip_tickets'
    )->fetchAll();

    $invalidNumbers = 0;

    foreach ($tickets as $ticket) {
        $date = new DateTimeImmutable($ticket['transaction_date']);

        $expected = sprintf(
            '%04d-%s-%0' . $width . 'd',
            (int) $ticket['ticket_year'],
            $date->format('m'),
            (int) $ticket['ticket_sequence']
        );

        if (
            (int) $date->format('Y') !== (int) $ticket['ticket_year'] ||
            $ticket['ticket_number'] !== $expected
        ) {
            $invalidNumbers++;
        }
    }

    checkResult(
        'Ticket numbers match their transaction year/month and sequence',
        $invalidNumbers === 0
    );

    $invalidCounters = countQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM (
             SELECT ticket_year,
                    MAX(ticket_sequence) AS highest_sequence
             FROM trip_tickets
             GROUP BY ticket_year
         ) t
         LEFT JOIN annual_counters c
           ON c.ticket_year = t.ticket_year
         WHERE c.ticket_year IS NULL
            OR c.last_sequence < t.highest_sequence"
    );

    checkResult(
        'Annual counters cover all allocated sequences',
        $invalidCounters === 0
    );

    $columns = $pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'users' AND column_name = 'session_version') OR (table_name = 'trip_tickets' AND column_name = 'submission_token'))")->fetchAll();
    checkResult('Session revocation and submission columns exist', count($columns) === 2);
    checkResult('Submission tokens remain unique', countQuery($pdo, "SELECT COUNT(*) FROM (SELECT submission_token FROM trip_tickets WHERE submission_token IS NOT NULL GROUP BY submission_token HAVING COUNT(*) > 1) duplicates") === 0);
    checkResult('All application tables use InnoDB', countQuery($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','vehicles','drivers','annual_counters','trip_tickets','ticket_returns','audit_logs','ai_settings','schema_migrations') AND engine <> 'InnoDB'") === 0);
    $uniqueKeys = $pdo->query("SELECT table_name AS source_table, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND non_unique = 0 GROUP BY table_name, index_name")->fetchAll();
    $actualKeys = array_map(static fn($row) => $row['source_table'] . ':' . $row['columns_list'], $uniqueKeys);
    checkResult('Numbering, submission and active-return uniqueness enforced', !array_diff(['trip_tickets:ticket_number', 'trip_tickets:ticket_year,ticket_sequence', 'trip_tickets:submission_token', 'ticket_returns:active_ticket_id'], $actualKeys));
    checkResult('Persistent AI settings row exists', countQuery($pdo, 'SELECT COUNT(*) FROM ai_settings WHERE id = 1') === 1);
    checkResult('Versioned migrations are recorded', countQuery($pdo, "SELECT COUNT(*) FROM schema_migrations WHERE version IN ('001_session_submission','002_ai_settings','003_timestamp_constraints')") === 3);

    echo "\n========================================\n";
    echo "DATABASE SUMMARY\n";
    echo "========================================\n";
    echo "Issued tickets     : $totalIssued\n";
    echo "Returned tickets   : $totalReturned\n";
    echo "Outstanding tickets: $totalOutstanding\n";
    echo "Active staff       : $staffCount\n";
    echo "Passed checks      : $passed\n";
    echo "Failed checks      : $failed\n\n";

    if ($failed === 0) {
        echo "DATABASE INTEGRITY CHECKS PASSED.\n";
        echo "This read-only check does not replace the separate integration/browser suite.\n";
        exit(0);
    }

    echo "VERIFICATION FAILED. Review the checks above.\n";
    exit(1);

} catch (Throwable $e) {
    error_log('Verification failure: ' . $e->getMessage());
    echo "[ERROR] Verification could not complete.\n";
    echo "Check PHP error logs for details.\n";
    exit(1);
}
