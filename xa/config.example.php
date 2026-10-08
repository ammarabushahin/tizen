<?php
declare(strict_types=1);

/**
 * Copy to config.php and replace placeholders.
 * Keep this folder outside public_html when possible.
 */
return [
    'base_url' => 'https://xanalytica.online',

    'email' => 'YOUR_XANALYTICA_EMAIL',
    'password' => 'YOUR_XANALYTICA_PASSWORD',

    'timezone' => 'Europe/Stockholm',
    'target_hours' => [1, 5, 9, 13, 17, 21],
    'expected_users' => 11,

    // Cron runs every 5 minutes. Missed slots are retried/caught up automatically.
    'catchup_hours' => 48,
    'max_slots_per_run' => 1,

    // Network resilience.
    'connect_timeout_seconds' => 20,
    'request_timeout_seconds' => 120,
    'request_retries' => 3,
    'retry_delays_seconds' => [2, 5, 12],
    'pause_between_requests_ms' => 300,
    'max_runtime_seconds' => 900,

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=CPANEL_DATABASE;charset=utf8mb4',
        'user' => 'CPANEL_DATABASE_USER',
        'pass' => 'CPANEL_DATABASE_PASSWORD',
    ],

    // Optional fallback map if the API field name for X username changes.
    // Example: 108 => 'username_without_at'
    'x_username_map' => [],

    'lock_file' => __DIR__ . '/xa-refresh.lock',
    'log_file' => __DIR__ . '/logs/refresh.log',

    // Optional JSON health endpoint protection.
    'status_key' => 'CHANGE_THIS_TO_A_LONG_RANDOM_VALUE',
];
