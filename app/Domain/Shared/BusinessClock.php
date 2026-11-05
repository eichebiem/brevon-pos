<?php

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;

/**
 * Business-calendar time (D-031): storage is UTC, business dates are
 * Asia/Manila (`brevon.business_timezone`).
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('brevon.business_timezone', 'Asia/Manila');
    }

    /** Current moment expressed in the business time zone. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** Start of today's business date (Asia/Manila). */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }
}
