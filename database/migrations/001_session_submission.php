<?php
// Resumable: inspect each object because MySQL DDL commits independently.
return static function (PDO $pdo): void {
    $column = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    foreach ([['users', 'session_version', 'INT UNSIGNED NOT NULL DEFAULT 1'], ['trip_tickets', 'submission_token', 'CHAR(64) NULL']] as [$table, $name, $type]) {
        $column->execute([$table, $name]);
        if (!(int) $column->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $type");
        }
    }
    $indexes = $pdo->query("SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'trip_tickets' AND non_unique = 0 GROUP BY index_name")->fetchAll();
    $found = false;
    foreach ($indexes as $index) {
        if ($index['columns_list'] === 'submission_token') {
            $found = true;
        }
    }
    if (!$found) {
        $pdo->exec('ALTER TABLE trip_tickets ADD UNIQUE KEY uq_submission_token (submission_token)');
    }
};
