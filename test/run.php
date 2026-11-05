<?php
// Integration tests create NEW databases only; no DROP/TRUNCATE or live mutations.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Manila');
$root = dirname(__DIR__);
$source = require (getenv('TRIPLEDGER_CONFIG') ?: $root . '/app/config.php');
$config = $source;
$database = $config['database'];
$server = new PDO("mysql:host={$database['host']};port={$database['port']};charset=utf8mb4", $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'tripledger_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$runtime = __DIR__ . '/.runtime';
if (!is_dir($runtime)) mkdir($runtime, 0700, true);
$config['database']['name'] = $name;
$config['gemini'] = ['model' => 'gemini-test', 'allowed_models' => ['gemini-test'], 'key_file' => $runtime . '/missing-key'];
$configPath = $runtime . '/config.php';
file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
@chmod($configPath, 0600);
putenv('TRIPLEDGER_CONFIG=' . $configPath);
require_once $root . '/app/ticket_functions.php';
require_once $root . '/app/return_functions.php';
require_once $root . '/app/correction_functions.php';
require_once $root . '/app/report_functions.php';
require_once $root . '/app/chatbot.php';
require_once $root . '/app/migrations.php';
require_once $root . '/app/audit.php';
$pdo = db();
$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void {
    global $passed, $failed;
    if ($ok) $passed++; else $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
}
function rejects(callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException | RuntimeException $e) { return true; }
}
function ticketId(string $number): int {
    $stmt = db()->prepare('SELECT id FROM trip_tickets WHERE ticket_number = ?');
    $stmt->execute([$number]); return (int) $stmt->fetchColumn();
}
function concurrent(array $jobs): array {
    $children = [];
    $start = microtime(true) + 1.0;
    foreach ($jobs as $job) {
        $job['start'] = $start;
        $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', json_encode($job)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    return $results;
}
function schemaSql(): string {
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if (preg_match('/^\s*(USE |CREATE DATABASE|DROP )/mi', $sql)) throw new RuntimeException('Unsafe install schema.');
    return $sql;
}
function installSchema(PDO $pdo, string $sql): void {
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
}
installSchema($pdo, schemaSql());
check('Fresh schema installs into isolated database', count($pdo->query('SHOW TABLES')->fetchAll()) === 9);
check('Fresh migration rerun makes no changes', migrateTripLedger($pdo) === [] && migrateTripLedger($pdo) === []);
echo 'Database engine: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
// Verify upgrade from the legacy schema, without touching the existing installation.
$legacyName = 'tripledger_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$legacyName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$legacy = new PDO("mysql:host={$database['host']};port={$database['port']};dbname=$legacyName;charset=utf8mb4", $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacySql = explode('-- Persistent non-secret AI switches.', schemaSql())[0];
$legacySql = str_replace('DATETIME NOT NULL', 'DATETIME NULL', $legacySql);
$legacySql = preg_replace('/^\s*(session_version INT.*|submission_token CHAR.*|UNIQUE KEY uq_submission_token.*)\R/m', '', $legacySql);
installSchema($legacy, $legacySql);
$legacy->exec("INSERT INTO users (username,full_name,password_hash,role) VALUES ('legacy_demo','Legacy Demo','not-a-login','Authorized Staff')");
$legacy->exec("INSERT INTO vehicles (plate_number) VALUES ('OLD 1234')");
$legacy->exec("INSERT INTO drivers (full_name) VALUES ('Legacy Driver')");
$legacy->exec("INSERT INTO annual_counters VALUES (2025,1)");
$legacy->exec("INSERT INTO trip_tickets (ticket_number,ticket_year,ticket_sequence,transaction_date,vehicle_id,driver_id,created_by) VALUES ('2025-12-0001',2025,1,'2025-12-01',1,1,1)");
$legacy->exec("INSERT INTO audit_logs (user_id,trip_ticket_id,action,new_values) VALUES (1,1,'TICKET_CREATED','{\"ticket_number\":\"2025-12-0001\"}')");
check('Legacy migrations apply all versions', count(migrateTripLedger($legacy)) === 3);
check('Legacy migration rerun is safe', migrateTripLedger($legacy) === []);
check('Legacy migration retains ticket numbers, counters and audit history', $legacy->query('SELECT ticket_number FROM trip_tickets')->fetchColumn() === '2025-12-0001' && (int) $legacy->query('SELECT last_sequence FROM annual_counters')->fetchColumn() === 1 && (int) $legacy->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1);
$setupConfig = $config; $setupConfig['database']['name'] = $legacyName;
$setupPath = $runtime . '/setup-config.php';
file_put_contents($setupPath, '<?php return ' . var_export($setupConfig, true) . ';'); @chmod($setupPath, 0600);
$setupPassword = bin2hex(random_bytes(16));
putenv('TRIPLEDGER_CONFIG=' . $setupPath);
try {
    $setup = proc_open([PHP_BINARY, $root . '/scripts/create_admin.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file',$runtime . '/setup-error.log','w']], $setupPipes, $root, null, ['bypass_shell' => true]);
    fwrite($setupPipes[0], "setup_demo\nSetup Demo\n$setupPassword\n$setupPassword\n"); fclose($setupPipes[0]);
    $setupOutput = stream_get_contents($setupPipes[1]); fclose($setupPipes[1]); proc_close($setup);
    check('Authorized CLI bootstrap creates first Administrator', str_contains($setupOutput, 'SUCCESS: Administrator account created') && (int) $legacy->query("SELECT COUNT(*) FROM users WHERE role='Administrator'")->fetchColumn() === 1);
    $setup = proc_open([PHP_BINARY, $root . '/scripts/create_admin.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file',$runtime . '/setup-error.log','a']], $setupPipes, $root, null, ['bypass_shell' => true]);
    fclose($setupPipes[0]); $setupOutput = stream_get_contents($setupPipes[1]); fclose($setupPipes[1]); proc_close($setup);
    check('Administrator bootstrap refuses a second account', str_contains($setupOutput, 'Administrator account already exists'));
} finally {
    putenv('TRIPLEDGER_CONFIG=' . $configPath);
    unlink($setupPath);
}
$password = bin2hex(random_bytes(16));
$stmt = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)');
foreach (['Administrator', 'Authorized Staff', 'Authorized Staff', 'Authorized Staff'] as $i => $role) {
    $stmt->execute(['demo_' . $i, 'Demo Account', password_hash($password, PASSWORD_DEFAULT), $role]);
}
$admin = 1; $staff = 2;
$token = bin2hex(random_bytes(32));
$october = createTripTicket($staff, '2026-10-01', 'TEST 1234', 'Demo Driver', '', $token);
check('First annual ticket uses transaction month/year', $october === '2026-10-0001');
check('Repeated submission returns same number', createTripTicket($staff, '2026-10-01', 'TEST 1234', 'Demo Driver', '', $token) === $october && (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets')->fetchColumn() === 1);
$november = createTripTicket($staff, '2026-11-01', 'TEST 1234', 'Demo Driver', '', bin2hex(random_bytes(32)));
$nextYear = createTripTicket($staff, '2027-01-01', 'TEST 1234', 'Demo Driver', '', bin2hex(random_bytes(32)));
$backdated = createTripTicket($staff, '2026-09-01', 'TEST 1234', 'Demo Driver', '', bin2hex(random_bytes(32)));
check('Sequence continues across months, separates years and allocates backdates', $november === '2026-11-0002' && $nextYear === '2027-01-0001' && $backdated === '2026-09-0003');
check('Invalid date and plate rejected', rejects(fn() => createTripTicket($staff, '2026-02-30', 'TEST 1234', 'Demo Driver', '', bin2hex(random_bytes(32)))) && rejects(fn() => correctTripTicket(ticketId($october), $staff, '2026-10-01', '=bad', 'Demo Driver', '')));
$id = ticketId($october);
check('Month/year correction requires manual review', rejects(fn() => correctTripTicket($id, $staff, '2026-11-01', 'TEST 1234', 'Demo Driver', '')));
correctTripTicket($id, $staff, '2026-10-02', 'TEST 5678', 'Demo Driver', 'Corrected demo');
$audit = $pdo->query("SELECT old_values,new_values FROM audit_logs WHERE action='ISSUANCE_CORRECTED'")->fetch();
check('Correction preserves ticket number and exact Before/After', ticketId($october) === $id && json_decode($audit['old_values'], true)['transaction_date'] === '2026-10-01' && json_decode($audit['new_values'], true)['transaction_date'] === '2026-10-02');
check('Return before transaction date rejected', rejects(fn() => saveReturnAction($id, $staff, 'record', '2026-10-01')));
saveReturnAction($id, $staff, 'record', '2026-10-15', 'Demo return');
check('Duplicate active return rejected', rejects(fn() => saveReturnAction($id, $staff, 'record', '2026-10-16')));
check('Issuance cannot move after active return', rejects(fn() => correctTripTicket($id, $staff, '2026-10-20', 'TEST 5678', 'Demo Driver', '')));
saveReturnAction($id, $staff, 'correct', '2026-10-16', 'Demo correction');
$returnAudit = $pdo->query("SELECT old_values,new_values FROM audit_logs WHERE action='RETURN_CORRECTED' ORDER BY id DESC LIMIT 1")->fetch();
check('Return correction Before/After matches stored dates', json_decode($returnAudit['old_values'], true)['return_date'] === '2026-10-15' && json_decode($returnAudit['new_values'], true)['return_date'] === '2026-10-16');
$oldReturnId = (int) $pdo->query('SELECT id FROM ticket_returns WHERE reversed_at IS NULL')->fetchColumn();
check('Reversal requires a reason', rejects(fn() => saveReturnAction($id, $staff, 'reverse')));
saveReturnAction($id, $staff, 'reverse', null, 'Demo reversal');
check('Reversal keeps history and restores outstanding status', (int) $pdo->query('SELECT COUNT(*) FROM ticket_returns WHERE reversed_at IS NOT NULL')->fetchColumn() === 1 && trippyStatistics()['returned'] === 0);
saveReturnAction($id, $staff, 'record', '2026-11-01', 'Returned later');
check('Stale return form cannot reverse replacement return', rejects(fn() => saveReturnAction($id, $staff, 'reverse', null, 'Stale request', $oldReturnId)));
check('Returned-in-period uses active return date', str_contains(tripledgerChatbotReply('How many tickets were returned in October 2026?')['reply'], 'Matching records: 0') && str_contains(tripledgerChatbotReply('How many tickets were returned in November 2026?')['reply'], 'Matching records: 1'));
check('Outstanding month uses transaction date', str_contains(tripledgerChatbotReply('Show outstanding tickets for October 2026')['reply'], 'Matching records: 0'));
check('Plate and ticket queries use local database', tripledgerChatbotReply('Find tickets for plate TEST 1234')['source'] === 'database' && str_contains(tripledgerChatbotReply('Find ticket ' . $october)['reply'], $october));
check('Unsupported dates and filters require clarification', tripledgerChatbotReply('Show outstanding tickets for October')['source'] === 'system' && tripledgerChatbotReply('Show outstanding tickets for yesterday')['source'] === 'system' && tripledgerChatbotReply('Show tickets for driver Jane')['source'] === 'system' && tripledgerChatbotReply('Show tickets from 2026-02-30 to 2026-03-01')['source'] === 'system');
$jobs = [];
for ($i = 0; $i < 6; $i++) $jobs[] = ['action' => 'issue', 'staff' => $staff, 'date' => '2026-10-03', 'token' => bin2hex(random_bytes(32))];
$results = concurrent($jobs);
check('Six simultaneous issuances succeed without duplicates', count(array_filter($results, fn($r) => $r === 'OK')) === 6 && (int) $pdo->query('SELECT COUNT(*) - COUNT(DISTINCT ticket_number) FROM trip_tickets')->fetchColumn() === 0);
$duplicate = ['action' => 'issue', 'staff' => $staff, 'date' => '2026-10-03', 'token' => bin2hex(random_bytes(32))];
$before = (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets')->fetchColumn();
$results = concurrent(array_fill(0, 4, $duplicate));
check('Four simultaneous duplicate submissions create one ticket', count(array_filter($results, fn($r) => $r === 'OK')) === 4 && (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets')->fetchColumn() === $before + 1);
$returnJob = ['action' => 'record', 'staff' => $staff, 'ticket' => ticketId($backdated)];
$results = concurrent(array_fill(0, 4, $returnJob));
check('Simultaneous return recording permits one active return', count(array_filter($results, fn($r) => $r === 'OK')) === 1 && count(array_filter($results, fn($r) => $r === 'REJECTED')) === 3);
$returnJob['action'] = 'reverse';
$results = concurrent(array_fill(0, 4, $returnJob));
check('Simultaneous reversals preserve one reversal event', count(array_filter($results, fn($r) => $r === 'OK')) === 1 && count(array_filter($results, fn($r) => $r === 'REJECTED')) === 3);
$stats = trippyStatistics();
check('Issued equals Returned plus Outstanding', $stats['issued'] === $stats['returned'] + $stats['outstanding']);
check('Outstanding report and local lookup match', count(outstandingRecords($pdo, reportFilters(['plate' => 'TEST 1234', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']))) === trippySearchRecords('TEST1234', null, 'outstanding', ['from' => '2026-10-01', 'to' => '2026-10-31'])['total']);
check('CSV formula-like cells neutralized', safeCsvCell(' =1+1') === "' =1+1" && safeCsvCell("\t@SUM(A1)") === "'\t@SUM(A1)" && safeCsvCell('TEST 1234') === 'TEST 1234');
check('Audit allowlist suppresses secrets', !str_contains(auditSummary(json_encode(['password_hash' => 'SECRET', 'token' => 'SECRET', 'api_key' => 'SECRET', 'remarks' => 'Demo'])), 'SECRET'));
check('AI disabled by default and local search available', rejects(fn() => tripledgerGeminiGenerate('Fixed demo')) && tripledgerChatbotReply('Show outstanding tickets')['source'] === 'database');
saveAiSettings($admin, ['enabled' => 1, 'explanations_enabled' => 1, 'analysis_enabled' => 0, 'model' => 'gemini-test']);
check('AI settings persisted, audited, and feature-specific', (int) aiSettings()['enabled'] === 1 && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='AI_SETTINGS_CHANGED'")->fetchColumn() === 1 && rejects(fn() => aiRequireEnabled('analysis')));
check('Staff cannot save AI settings or choose unpermitted models', rejects(fn() => saveAiSettings($staff, ['model' => 'gemini-test'])) && rejects(fn() => saveAiSettings($admin, ['model' => 'unknown'])));
check('Missing Gemini key falls back without network', tripledgerChatbotReply('What is ticket reconciliation?')['source'] === 'system');
check('Gemini quota, malformed, incomplete and empty responses handled', rejects(fn() => tripledgerGeminiDecode('{}', 429)) && rejects(fn() => tripledgerGeminiDecode('{', 200)) && rejects(fn() => tripledgerGeminiDecode('{"candidates":[{"finishReason":"MAX_TOKENS"}]}', 200)) && rejects(fn() => tripledgerGeminiDecode('{}', 200)));
$decoded = tripledgerGeminiDecode('{"candidates":[{"finishReason":"STOP","content":{"parts":[{"thought":true,"text":"hidden"},{"text":"Visible explanation"}]}}]}', 200);
check('Gemini displays only visible response text', $decoded === 'Visible explanation');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$fixtureAddress = stream_socket_get_name($socket, false); fclose($socket);
check('Gemini connection failure is handled', rejects(fn() => tripledgerGeminiHttp('http://' . $fixtureAddress, 'demo-test-key', '{}', 1)));
$fixture = proc_open([PHP_BINARY, '-S', $fixtureAddress, __DIR__ . '/http_fixture.php'], [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/fixture.log', 'w'], 2 => ['file', $runtime . '/fixture.log', 'a']], $fixturePipes, $root, null, ['bypass_shell' => true]);
fclose($fixturePipes[0]);
try {
    usleep(500000);
    $started = microtime(true);
    $timeoutRejected = rejects(fn() => tripledgerGeminiHttp('http://' . $fixtureAddress, 'demo-test-key', '{}', 1));
    $elapsed = microtime(true) - $started;
    check('Gemini transport timeout is bounded and handled', $timeoutRejected && $elapsed >= 0.8 && $elapsed < 2.5);
} finally {
    proc_terminate($fixture); proc_close($fixture);
}
saveAiSettings($admin, ['model' => 'gemini-test']);
for ($i = 0; $i < 28; $i++) createTripTicket($staff, '2028-01-01', 'PAGE 1234', 'Page Demo', '', bin2hex(random_bytes(32)));
require __DIR__ . '/web_checks.php';
require __DIR__ . '/restore_check.php';
file_put_contents($runtime . '/last-run.json', json_encode(['database' => $name, 'legacy_database' => $legacyName, 'passed' => $passed, 'failed' => $failed], JSON_PRETTY_PRINT));
echo "Integration results: $passed passed; $failed failed. Test databases retained; live records untouched.\n";
exit($failed ? 1 : 0);
