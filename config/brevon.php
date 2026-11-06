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

    // FR-062 / BR-013 / D-047: negative stock is blocked by default.
    /*
    | Features kept for a later release (D-164). Their code and tables stay;
    | switched off, their screens answer 404 and they change nothing.
    */
    'features' => [
        // Tax rules (FR-086, D-149): the client does not charge tax for now.
        'tax_rules' => (bool) env('BREVON_FEATURE_TAX_RULES', false),
    ],

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
