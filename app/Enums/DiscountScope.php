<?php

namespace App\Enums;

/** What a discount applies to (FR-083; D-145). */
enum DiscountScope: string
{
    case Order = 'order';
    case Product = 'product';
    case Category = 'category';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Whole order',
            self::Product => 'One product',
            self::Category => 'One category',
        };
    }
}
