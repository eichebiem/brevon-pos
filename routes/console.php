<?php

use Illuminate\Support\Facades\Schedule;

// NFR-011, D-185: daily database backup. Needs the scheduler running on the
// server (`* * * * * php artisan schedule:run`, see docs/DEPLOYMENT.md).
Schedule::command('brevon:backup')
    ->dailyAt((string) config('brevon.backup.daily_at'))
    ->timezone((string) config('brevon.business_timezone'))
    ->withoutOverlapping();
