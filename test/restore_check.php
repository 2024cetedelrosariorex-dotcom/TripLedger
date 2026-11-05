<?php
// Exercise standard mysqldump + mysql restore with isolated demonstration data.
$mysqlDir = getenv('TRIPLEDGER_MYSQL_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/MySQL/MySQL Server 8.0/bin/' : '');
$mysqlDir = $mysqlDir === '' ? '' : rtrim($mysqlDir, '/\\') . '/';
$suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
$optionFile = $runtime . '/mysql-client.cnf';
$escape = static fn($value) => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value) . '"';
file_put_contents($optionFile, "[client]\nhost=" . $escape($database['host']) . "\nport=" . (int) $database['port'] . "\nuser=" . $escape($database['username']) . "\npassword=" . $escape($database['password']) . "\n");
@chmod($optionFile, 0600);
$dump = $runtime . '/demonstration-backup.sql';
try {
    $proc = proc_open([$mysqlDir . 'mysqldump' . $suffix, '--defaults-extra-file=' . $optionFile, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--result-file=' . $dump, $name],
        [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/dump-output.log', 'w'], 2 => ['file', $runtime . '/dump-error.log', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fclose($pipes[0]); $dumpCode = proc_close($proc);
    check('Credential-free command line backup succeeds', $dumpCode === 0 && filesize($dump) > 0);
    if ($dumpCode !== 0) return;
    $restoreName = 'tripledger_test_' . bin2hex(random_bytes(6));
    $server->exec("CREATE DATABASE `$restoreName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $proc = proc_open([$mysqlDir . 'mysql' . $suffix, '--defaults-extra-file=' . $optionFile, $restoreName],
        [0 => ['file', $dump, 'r'], 1 => ['file', $runtime . '/restore-output.log', 'w'], 2 => ['file', $runtime . '/restore-error.log', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $restoreCode = proc_close($proc);
    $restored = new PDO("mysql:host={$database['host']};port={$database['port']};dbname=$restoreName;charset=utf8mb4", $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $same = $restoreCode === 0;
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        // Exact row comparison includes counters, audit JSON and generated active-return keys.
        $originalRows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $restoredRows = $restored->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $same = $same && $originalRows === $restoredRows;
    }
    check('Backup restores all table rows exactly into a separate database', $same);
} finally {
    // Only this newly generated credentials file is removed, never an existing file/database.
    if (is_file($optionFile)) unlink($optionFile);
}
