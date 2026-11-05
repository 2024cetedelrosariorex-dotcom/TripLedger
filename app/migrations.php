<?php

function migrateTripLedger(PDO $pdo): array
{
    if ((int) $pdo->query("SELECT GET_LOCK('tripledger_schema_migration', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Another migration is running.');
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $ran = [];
        foreach (glob(__DIR__ . '/../database/migrations/*.php') as $file) {
            $version = basename($file, '.php');
            if (in_array($version, $applied, true)) continue;
            $migration = require $file;
            $migration($pdo);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $stmt->execute([$version]);
            $ran[] = $version;
        }
        return $ran;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('tripledger_schema_migration')");
    }
}
