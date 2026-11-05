<?php
// Separate processes exercise actual InnoDB contention; no live database allowed.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/ticket_functions.php';
require_once __DIR__ . '/../app/return_functions.php';
if (!preg_match('/^tripledger_test_[a-f0-9]+$/', tripledgerConfig()['database']['name'])) exit(2);
$job = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
while (microtime(true) < $job['start']) usleep(1000);
try {
    if ($job['action'] === 'issue') {
        createTripTicket($job['staff'], $job['date'], 'TEST 1234', 'Demo Driver', '', $job['token']);
    } else {
        saveReturnAction($job['ticket'], $job['staff'], $job['action'], '2026-10-20', 'Demonstration reason');
    }
    echo 'OK';
} catch (InvalidArgumentException $e) {
    echo 'REJECTED';
} catch (Throwable $e) {
    echo 'ERROR';
    exit(1);
}
