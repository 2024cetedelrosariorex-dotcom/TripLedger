<?php
// Included by run.php with an isolated database and random demonstration password.
class DemoBrowser {
    private $curl;
    public string $body = '';
    public int $status = 0;
    public string $redirect = '';
    public function __construct(private string $base) {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    }
    public function request(string $path, ?array $form = null, bool $json = false): self {
        curl_setopt_array($this->curl, [CURLOPT_URL => $this->base . '/' . $path, CURLOPT_POST => $form !== null,
            CURLOPT_HTTPHEADER => $json ? ['Content-Type: application/json'] : [],
            CURLOPT_HEADERFUNCTION => static fn($curl, $line) => strlen($line)]);
        if ($form !== null) curl_setopt($this->curl, CURLOPT_POSTFIELDS, $json ? json_encode($form) : http_build_query($form));
        else curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        $this->body = (string) curl_exec($this->curl);
        $this->status = (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $this->redirect = (string) curl_getinfo($this->curl, CURLINFO_REDIRECT_URL);
        return $this;
    }
    public function token(string $name = 'csrf_token'): string {
        if (preg_match('/name="' . preg_quote($name, '/') . '"\s+(?:value|content)="([a-f0-9]+)"/', $this->body, $m)) return $m[1];
        throw new RuntimeException('Test page did not provide a form token.');
    }
    public function login(string $username, string $password): bool {
        $this->request('index.php'); $token = $this->token();
        $this->request('index.php', ['csrf_token' => $token, 'username' => $username, 'password' => $password]);
        return $this->status === 302;
    }
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate local HTTP test port.');
$address = stream_socket_get_name($socket, false); fclose($socket);
$serverLog = $runtime . '/http.log';
if (!is_dir($runtime . '/sessions')) mkdir($runtime . '/sessions', 0700);
$httpServer = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-d', 'display_errors=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $root . '/public'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $httpPipes, $root, null, ['bypass_shell' => true]);
fclose($httpPipes[0]);
$base = 'http://' . $address;
try {
    $guest = new DemoBrowser($base);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $guest->request('index.php'); if ($guest->status === 200) break;
        usleep(100000);
    }
    check('Login works with output buffering disabled', $guest->status === 200 && !str_contains($guest->body, 'Warning:'));
    $staffPages = ['dashboard.php', 'records.php', 'issuance.php', 'outstanding.php', 'export_outstanding.php', 'ticket_details.php?id=' . $id, 'correct_ticket.php?id=' . $id, 'ai_assistant.php'];
    $adminPages = ['admin_dashboard.php', 'admin_staff.php', 'admin_audit.php', 'admin_ai.php'];
    $protected = true;
    foreach (array_merge($staffPages, $adminPages, ['admin_staff_action.php']) as $path) {
        $guest->request($path); $protected = $protected && $guest->status === 302;
    }
    foreach (['api_chatbot.php', 'api_reconciliation_ai.php'] as $path) {
        $guest->request($path); $protected = $protected && $guest->status === 401 && is_array(json_decode($guest->body, true));
    }
    check('All protected routes reject anonymous access', $protected);
    $adminBrowser = new DemoBrowser($base);
    $staffBrowser = new DemoBrowser($base);
    check('Admin login redirects to Admin dashboard', $adminBrowser->login('demo_0', $password) && str_ends_with($adminBrowser->redirect, 'admin_dashboard.php'));
    check('Staff login redirects to operational dashboard', $staffBrowser->login('demo_1', $password) && str_ends_with($staffBrowser->redirect, 'dashboard.php'));
    $roles = true;
    foreach ($staffPages as $path) {
        $adminBrowser->request($path); $roles = $roles && $adminBrowser->status === 403;
        $staffBrowser->request($path); $roles = $roles && $staffBrowser->status === 200;
    }
    foreach ($adminPages as $path) {
        $staffBrowser->request($path); $roles = $roles && $staffBrowser->status === 403;
        $adminBrowser->request($path); $roles = $roles && $adminBrowser->status === 200;
    }
    foreach (['api_chatbot.php', 'api_reconciliation_ai.php'] as $path) {
        $adminBrowser->request($path); $roles = $roles && $adminBrowser->status === 403;
    }
    $staffBrowser->request('admin_staff_action.php', []); $roles = $roles && $staffBrowser->status === 403;
    check('Staff/Admin boundaries enforced on every route', $roles);
    $adminBrowser->request('admin_staff.php'); $adminToken = $adminBrowser->token();
    $adminBrowser->request('admin_staff.php', ['csrf_token' => $adminToken, 'form_action' => 'create_staff', 'username' => 'demo_new', 'full_name' => 'Demonstration Staff', 'password' => $password, 'confirm_password' => $password]);
    $newId = (int) $pdo->query("SELECT id FROM users WHERE username='demo_new'")->fetchColumn();
    check('Admin creates Staff through audited web workflow', $adminBrowser->status === 303 && $newId > 0 && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='STAFF_ACCOUNT_CREATED'")->fetchColumn() === 1);
    $newBrowser = new DemoBrowser($base); $newBrowser->login('demo_new', $password);
    $adminBrowser->request('admin_staff_action.php', ['csrf_token' => $adminToken, 'staff_id' => (string) $newId, 'action' => 'deactivate']);
    $newBrowser->request('records.php');
    check('Deactivation immediately rejects existing session', $adminBrowser->status === 303 && $newBrowser->status === 302 && !$newBrowser->login('demo_new', $password));
    $adminBrowser->request('admin_staff_action.php', ['csrf_token' => $adminToken, 'staff_id' => (string) $newId, 'action' => 'activate']);
    check('Activation restores login access', $newBrowser->login('demo_new', $password));
    $newPassword = bin2hex(random_bytes(16));
    $adminBrowser->request('admin_staff_action.php', ['csrf_token' => $adminToken, 'staff_id' => (string) $newId, 'action' => 'reset_password', 'new_password' => $newPassword, 'confirm_password' => $newPassword]);
    $newBrowser->request('records.php');
    check('Password reset revokes sessions and requires new password', $newBrowser->status === 302 && !$newBrowser->login('demo_new', $password) && $newBrowser->login('demo_new', $newPassword));
    $actions = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE trip_ticket_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    check('Account lifecycle actions all audited', !array_diff(['STAFF_ACCOUNT_CREATED', 'STAFF_ACCOUNT_ACTIVATED', 'STAFF_ACCOUNT_DEACTIVATED', 'STAFF_PASSWORD_RESET'], $actions));
    $staffBrowser->request('issuance.php'); $csrf = $staffBrowser->token(); $submission = $staffBrowser->token('submission_token');
    $form = ['csrf_token' => $csrf, 'submission_token' => $submission, 'transaction_date' => '2026-10-04', 'plate_number' => 'WEB 1234', 'driver_name' => 'Web Demo', 'remarks' => ''];
    $before = (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets')->fetchColumn();
    $staffBrowser->request('issuance.php', $form); $issued = $staffBrowser->status === 303;
    $staffBrowser->request('issuance.php', $form);
    check('Browser issuance and double submit create only one ticket', $issued && (int) $pdo->query('SELECT COUNT(*) FROM trip_tickets')->fetchColumn() === $before + 1);
    $form['csrf_token'] = 'invalid'; $staffBrowser->request('issuance.php', $form);
    check('Mutation rejects invalid CSRF token', $staffBrowser->status === 403);
    $staffBrowser->request('records.php?ticket=' . urlencode($october));
    check('Records ticket-number search returns the matching ticket', $staffBrowser->status === 200 && str_contains($staffBrowser->body, $october) && !str_contains($staffBrowser->body, $november));
    $staffBrowser->request('records.php?plate=PAGE+1234&page=2');
    preg_match_all('/<strong>\s*(\d{4}-\d{2}-\d{4})\s*<\/strong>/', $staffBrowser->body, $pageMatches);
    check('Records pagination preserves filters and remaining rows', $staffBrowser->status === 200 && str_contains($staffBrowser->body, 'Page 2 of 2') && $pageMatches[1] === ['2028-01-0003', '2028-01-0002', '2028-01-0001']);
    $staffBrowser->request('records.php?date_from=2026-11-20&date_to=2026-10-01');
    check('Invalid date ranges display validation errors', str_contains($staffBrowser->body, 'Date From cannot be later'));
    $staffBrowser->request('records.php?plate[]=bad');
    check('Array filters rejected without PHP warnings', str_contains($staffBrowser->body, 'Invalid filter format.') && !str_contains($staffBrowser->body, 'Warning:'));
    $filter = 'plate=TEST+1234&date_from=2026-10-01&date_to=2026-10-31';
    $expected = outstandingRecords($pdo, reportFilters(['plate' => 'TEST 1234', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']));
    $staffBrowser->request('outstanding.php?' . $filter); $html = $staffBrowser->body;
    $staffBrowser->request('export_outstanding.php?' . $filter);
    $csv = ltrim($staffBrowser->body, "\xEF\xBB\xBF");
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    $match = count($rows) === count($expected) + 1;
    foreach ($expected as $record) $match = $match && str_contains($html, $record['ticket_number']) && str_contains($csv, $record['ticket_number']);
    check('Filtered report, printable content, CSV and query agree', $staffBrowser->status === 200 && $match);
    $staffBrowser->request('outstanding.php?plate=EMPTY9999');
    check('Outstanding empty state renders', $staffBrowser->status === 200 && str_contains(strtolower($staffBrowser->body), 'no outstanding'));
    $staffBrowser->request('ai_assistant.php'); $csrf = $staffBrowser->token('csrf-token');
    $staffBrowser->request('api_chatbot.php', ['csrf_token' => $csrf, 'message' => 'Show outstanding tickets'], true);
    $json = json_decode($staffBrowser->body, true);
    check('Trippy endpoint serves local lookup while AI disabled', $staffBrowser->status === 200 && ($json['source'] ?? '') === 'database');
    $staffBrowser->request('api_reconciliation_ai.php', ['csrf_token' => $csrf, 'plate' => '', 'date_from' => '', 'date_to' => ''], true);
    $json = json_decode($staffBrowser->body, true);
    check('Reconciliation endpoint falls back with AI disabled', $staffBrowser->status === 200 && ($json['source'] ?? '') === 'database_fallback');
    $staffBrowser->request('api_chatbot.php', ['csrf_token' => 'wrong', 'message' => 'help'], true);
    check('JSON endpoint rejects invalid CSRF', $staffBrowser->status === 403);
    $adminBrowser->request('admin_ai.php', ['csrf_token' => $adminToken, 'model' => 'unpermitted']);
    check('AI settings endpoint validates permitted configuration', str_contains($adminBrowser->body, 'Select a model permitted'));
    $adminBrowser->request('admin_audit.php?action=ISSUANCE_CORRECTED&search=' . urlencode($october));
    check('Admin audit shows ticket, actor and Before/After with filters', $adminBrowser->status === 200 && str_contains($adminBrowser->body, '2026-10-01') && str_contains($adminBrowser->body, '2026-10-02') && str_contains($adminBrowser->body, 'Demo Account'));
    $adminBrowser->request('admin_audit.php?page=999');
    check('Audit pagination clamps to a valid page', $adminBrowser->status === 200 && !str_contains($adminBrowser->body, 'Page 999'));
    $newBrowser->request('dashboard.php'); $logoutToken = $newBrowser->token();
    $newBrowser->request('logout.php', ['csrf_token' => $logoutToken]);
    $newBrowser->request('records.php');
    check('Logout clears authenticated access', $newBrowser->status === 302);
    // Resolve every local asset and static PHP link seen in both roles' pages.
    $linksOk = true;
    foreach (array_merge($staffPages, $adminPages) as $path) {
        $browser = str_starts_with($path, 'admin_') ? $adminBrowser : $staffBrowser;
        $browser->request($path);
        preg_match_all('/(?:href|src)="([^"<>]+)"/', $browser->body, $matches);
        foreach ($matches[1] as $link) {
            $local = parse_url(html_entity_decode($link), PHP_URL_PATH);
            if (!$local || str_starts_with($link, '#') || preg_match('/^(https?:|data:)/', $link)) continue;
            $linksOk = $linksOk && is_file($root . '/public/' . $local);
        }
    }
    check('Rendered local links and assets resolve', $linksOk);
    $chrome = getenv('TRIPLEDGER_CHROME') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    if (is_file($chrome)) {
        $browserProcess = proc_open(['node', __DIR__ . '/browser.mjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $runtime . '/browser-error.log', 'w']], $browserPipes, $root, null, ['bypass_shell' => true]);
        fwrite($browserPipes[0], json_encode(['base' => $base, 'runtime' => $runtime, 'chrome' => $chrome, 'password' => $password, 'ticket' => $id]));
        fclose($browserPipes[0]);
        $browserResults = json_decode(stream_get_contents($browserPipes[1]), true);
        fclose($browserPipes[1]); proc_close($browserProcess);
        if (is_array($browserResults)) {
            foreach ($browserResults as $result) {
                check($result['name'], $result['ok']);
                if (isset($result['detail'])) echo 'Browser diagnostic: ' . $result['detail'] . "\n";
            }
        } else check('Browser automation returned results', false);
    } else {
        echo "[NOT RUN] Browser smoke tests: set TRIPLEDGER_CHROME to a Chrome/Chromium executable.\n";
    }
} finally {
    proc_terminate($httpServer); proc_close($httpServer);
}
