<?php
// Copy to app/config.php (git ignored), or set TRIPLEDGER_CONFIG to a private PHP file.
return [
    'database' => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => 'tripledger',
        'charset' => 'utf8mb4', 'username' => 'tripledger_app', 'password' => '',
    ],
    'ticket_numbering' => ['sequence_width' => 4],
    'gemini' => [
        // Configure a model available to your project; no network call is made by default.
        'model' => '',
        'allowed_models' => [],
        // Absolute file outside the web/document root, readable only by the server operator/PHP.
        'key_file' => '',
    ],
];
