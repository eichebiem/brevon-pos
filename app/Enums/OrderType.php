<?php

namespace App\Enums;

/** FR-028, D-029, D-181. The POS offers these three, in this order. */
enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeout = 'takeout';
    case AdvanceOrder = 'advance_order';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Dine-in',
            self::Takeout => 'Takeout',
            self::AdvanceOrder => 'Advance Order',
        };
    }
}
