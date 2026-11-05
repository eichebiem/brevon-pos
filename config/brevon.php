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

    // BR-017 / D-041: a cashier needs an open shift to accept cash payments.
    'shift_enforcement' => (bool) env('BREVON_SHIFT_ENFORCEMENT', true),

    // FR-062 / BR-013 / D-047: negative stock is blocked by default.
    'allow_negative_stock' => (bool) env('BREVON_ALLOW_NEGATIVE_STOCK', false),

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
