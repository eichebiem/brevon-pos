<?php

/*
|--------------------------------------------------------------------------
| Brevon business configuration
|--------------------------------------------------------------------------
|
| Timestamps are stored in UTC (config/app.php). Business dates, report
| boundaries and on-screen times use the business timezone (BR-020,
| NFR-015, D-031). Values marked TBC in docs/DECISIONS.md are configurable.
|
*/

return [

    'business_timezone' => env('BREVON_BUSINESS_TIMEZONE', 'Asia/Manila'),

    'currency' => env('BREVON_CURRENCY', 'PHP'),

    'currency_symbol' => '₱',

    // NFR-001, D-183: redirect HTTP to HTTPS and send HSTS. On by default in
    // production; set BREVON_FORCE_HTTPS=false only for a closed local network.
    'force_https' => (bool) env('BREVON_FORCE_HTTPS', env('APP_ENV') === 'production'),

    // D-183: behind a hosting load balancer or proxy, its address(es),
    // comma-separated, or '*'. Empty = no proxy trusted.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // NFR-011, D-185: `php artisan brevon:backup` (scheduled daily). Copy the
    // backup folder off the server too: a backup on the same disk is not enough.
    'backup' => [
        'path' => env('BREVON_BACKUP_PATH', storage_path('app/private/backups')),
        'keep_days' => (int) env('BREVON_BACKUP_KEEP_DAYS', 14),
        'mysqldump' => env('BREVON_MYSQLDUMP_PATH', 'mysqldump'),
        'daily_at' => env('BREVON_BACKUP_TIME', '02:00'),
    ],

    // FR-062 / BR-013 / D-047: negative stock is blocked by default.
    'allow_negative_stock' => (bool) env('BREVON_ALLOW_NEGATIVE_STOCK', false),

    // Local-only owner bootstrap for `php artisan brevon:create-owner` (D-022).
    // Leave empty outside local development.
    'dev_owner' => [
        'email' => env('BREVON_DEV_OWNER_EMAIL'),
        'password' => env('BREVON_DEV_OWNER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Destructive database command guard (D-012)
    |--------------------------------------------------------------------------
    |
    | The local MySQL server also hosts other projects' databases, including
    | production ones. Destructive artisan commands are refused unless the
    | environment AND the target database name are both listed here.
    |
    */

    'destructive_guard' => [
        'environments' => ['local', 'testing'],
        'drivers' => ['mysql'],
        'databases' => ['brevon_pos', 'brevon_pos_test'],
        'commands' => [
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'db:seed',
        ],
    ],

];
