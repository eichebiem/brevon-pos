<?php

namespace App\Enums;

/** Condition of a delivered quantity (UC-006 alternative flows; D-137). Only accepted stock is added. */
enum ReceiptCondition: string
{
    case Accepted = 'accepted';
    case Damaged = 'damaged';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Damaged => 'Damaged',
            self::Expired => 'Expired',
        };
    }
}
