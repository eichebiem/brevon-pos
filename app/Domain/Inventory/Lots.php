<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\BusinessClock;
use App\Models\Branch;
use App\Models\InventoryLot;

/**
 * Expiry status of received lots (FR-060; D-138). A lot counts while it
 * still has stock left by the first-expiring-first estimate.
 */
class Lots
{
    /** Lots expiring within this many days are flagged. */
    public const EXPIRING_DAYS = 7;

    /**
     * @return array{expiring: int, expired: int}
     */
    public function counts(Branch $location): array
    {
        $today = BusinessClock::today()->toDateString();
        $soon = BusinessClock::today()->addDays(self::EXPIRING_DAYS)->toDateString();
        $base = InventoryLot::query()->where('branch_id', $location->id)->where('quantity_remaining', '>', 0)->whereNotNull('expires_at');

        return [
            'expiring' => (clone $base)->where('expires_at', '>=', $today)->where('expires_at', '<=', $soon)->count(),
            'expired' => (clone $base)->where('expires_at', '<', $today)->count(),
        ];
    }

    /**
     * @return 'expired'|'expiring'|'used'|'ok'
     */
    public static function state(InventoryLot $lot, string $today, string $soon): string
    {
        $expires = $lot->expires_at?->toDateString();

        return match (true) {
            ! is_numeric($lot->quantity_remaining) || bccomp($lot->quantity_remaining, '0', 3) <= 0 => 'used',
            $expires !== null && $expires < $today => 'expired',
            $expires !== null && $expires <= $soon => 'expiring',
            default => 'ok',
        };
    }
}
