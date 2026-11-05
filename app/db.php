<?php

require_once __DIR__ . '/configuration.php';

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $config = tripledgerConfig();
    $database = $config['database'];

    date_default_timezone_set('Asia/Manila');

    $dsn = "mysql:host={$database['host']};port={$database['port']};dbname={$database['name']};charset={$database['charset']}";

    try {
        $connection = new PDO(
            $dsn,
            $database['username'],
            $database['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        $connection->exec("SET time_zone = '+08:00'");

        return $connection;

    } catch (PDOException $e) {
        error_log($e->getMessage());

        throw new RuntimeException(
            'Database connection failed. Check your MySQL settings.'
        );
    }
}
