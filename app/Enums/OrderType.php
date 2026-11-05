<?php

namespace App\Enums;

/** FR-028, D-029. All five are enabled in V1. */
enum OrderType: string
{
    case WalkIn = 'walk_in';
    case DineIn = 'dine_in';
    case Takeout = 'takeout';
    case AdvancePickup = 'advance_pickup';
    case Bulk = 'bulk';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::DineIn => 'Dine-in',
            self::Takeout => 'Takeout',
            self::AdvancePickup => 'Advance pickup',
            self::Bulk => 'Bulk',
        };
    }

    /** Orders collected later need a pickup date and time. */
    public function needsPickupTime(): bool
    {
        return $this === self::AdvancePickup;
    }
}
