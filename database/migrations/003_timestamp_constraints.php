<?php
// Align legacy timestamp nullability with schema.sql without inventing historical dates.
return static function (PDO $pdo): void {
    $targets = [
        ['users', 'created_at'], ['vehicles', 'created_at'], ['drivers', 'created_at'],
        ['trip_tickets', 'created_at'], ['trip_tickets', 'updated_at'],
        ['ticket_returns', 'recorded_at'], ['audit_logs', 'created_at'],
    ];
    // Preflight every column before any DDL. Missing historical dates require human review.
    foreach ($targets as [$table, $column]) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE `$column` IS NULL")->fetchColumn() !== 0) {
            throw new RuntimeException("Migration stopped: $table.$column has missing timestamps requiring manual review. No dates were invented.");
        }
    }
    $metadata = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    foreach ($targets as [$table, $column]) {
        $metadata->execute([$table, $column]);
        if ($metadata->fetchColumn() === 'YES') {
            $onUpdate = $column === 'updated_at' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP$onUpdate");
        }
    }
};
