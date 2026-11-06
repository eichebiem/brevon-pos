<?php

namespace App\Enums;

/** A full count of what is on the shelf, or a known quantity added or removed (D-131). */
enum StockAdjustmentKind: string
{
    case Count = 'count';
    case Change = 'change';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Stock count',
            self::Change => 'Adjustment',
        };
    }
}
