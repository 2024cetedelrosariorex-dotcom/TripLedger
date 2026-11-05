<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../app/db.php';
$destination = $argv[1] ?? '';
$directory = realpath(dirname($destination));
$project = str_replace('\\', '/', realpath(dirname(__DIR__)));
if ($destination === '' || !$directory || file_exists($destination)
    || str_starts_with(strtolower(str_replace('\\', '/', $directory) . '/'), strtolower($project . '/'))) {
    fwrite(STDERR, "Supply a new .sql file in an existing private directory outside the project and every web root. Existing files are never overwritten.\n");
    exit(1);
}
if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'sql') exit("Use a .sql filename.\n");
$config = tripledgerConfig()['database'];
$bin = getenv('TRIPLEDGER_MYSQL_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/MySQL/MySQL Server 8.0/bin/' : '');
$executable = ($bin === '' ? '' : rtrim($bin, '/\\') . '/') . 'mysqldump' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
$option = tempnam(sys_get_temp_dir(), 'tl-client-');
$errors = tempnam(sys_get_temp_dir(), 'tl-dump-');
@chmod($option, 0600); @chmod($errors, 0600);
$exitCode = 0;
$quote = static fn($v) => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v) . '"';
try {
    file_put_contents($option, "[client]\nhost=" . $quote($config['host']) . "\nport=" . (int) $config['port'] . "\nuser=" . $quote($config['username']) . "\npassword=" . $quote($config['password']) . "\n");
    $process = proc_open([$executable, '--defaults-extra-file=' . $option, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--skip-add-drop-table', '--result-file=' . $destination, $config['name']],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Backup client could not start.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0 || !is_file($destination) || filesize($destination) === 0) {
        throw new RuntimeException('Backup failed. Check client compatibility, credentials and destination permissions. Any partial output is not a valid backup.');
    }
    @chmod($destination, 0600);
    echo "Backup created: $destination\nSHA256: " . hash_file('sha256', $destination) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    unlink($option); unlink($errors);
}
exit($exitCode);
