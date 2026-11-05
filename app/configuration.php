<?php

function tripledgerConfig(): array
{
    static $config;
    if ($config === null) {
        $path = getenv('TRIPLEDGER_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Server configuration is missing. See docs/SETUP.md.');
        }
        // Configuration files must never leak accidental whitespace into HTTP responses.
        ob_start();
        try {
            $config = require $path;
        } finally {
            ob_end_clean();
        }
        if (!is_array($config)) {
            throw new RuntimeException('Invalid server configuration.');
        }
    }
    return $config;
}
