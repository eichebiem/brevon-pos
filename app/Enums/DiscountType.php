<?php

namespace App\Enums;

/** How a discount is worked out (FR-083). */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
        };
    }
}
