<?php
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_settings (
        id TINYINT UNSIGNED PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        explanations_enabled TINYINT(1) NOT NULL DEFAULT 0,
        analysis_enabled TINYINT(1) NOT NULL DEFAULT 0,
        model VARCHAR(100) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec('INSERT IGNORE INTO ai_settings (id) VALUES (1)');
};
