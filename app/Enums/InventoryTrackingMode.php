<?php

namespace App\Enums;

/** How a sale affects stock (D-046). */
enum InventoryTrackingMode: string
{
    case FinishedStock = 'finished_stock';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::FinishedStock => 'Deduct finished stock at sale',
            self::None => 'No stock tracking',
        };
    }
}
