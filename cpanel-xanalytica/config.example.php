<?php
declare(strict_types=1);

/**
 * Copy this file to config.php and fill in the real values.
 * Keep config.php outside public_html when possible.
 */
return [
    'base_url' => 'https://xanalytica.online',

    'email' => 'YOUR_XANALYTICA_EMAIL',
    'password' => 'YOUR_XANALYTICA_PASSWORD',

    'timezone' => 'Europe/Stockholm',
    'target_hours' => [1, 5, 9, 13, 17, 21],
    'expected_users' => 11,

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=CPANEL_DATABASE;charset=utf8mb4',
        'user' => 'CPANEL_DATABASE_USER',
        'pass' => 'CPANEL_DATABASE_PASSWORD',
    ],

    'connect_timeout_seconds' => 20,
    'request_timeout_seconds' => 120,
    'max_runtime_seconds' => 900,
    'pause_between_requests_ms' => 350,

    'lock_file' => __DIR__ . '/xanalytica-refresh.lock',
    'log_file' => __DIR__ . '/logs/refresh.log',
];
