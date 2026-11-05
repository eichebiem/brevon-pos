<?php

namespace App\Enums;

/** SRS products.product_type. */
enum ProductType: string
{
    case FinishedGood = 'finished_good';
    case MadeToOrder = 'made_to_order';
    case Retail = 'retail';

    public function label(): string
    {
        return match ($this) {
            self::FinishedGood => 'Finished good (baked ahead, e.g. doughnuts)',
            self::MadeToOrder => 'Made to order (e.g. coffee)',
            self::Retail => 'Retail item (bought in, resold)',
        };
    }

    /**
     * Allowed stock tracking per type (D-085).
     *
     * @return list<InventoryTrackingMode>
     */
    public function allowedTrackingModes(): array
    {
        return match ($this) {
            self::FinishedGood => [InventoryTrackingMode::FinishedStock],
            self::MadeToOrder => [InventoryTrackingMode::RecipeAtSale, InventoryTrackingMode::None],
            self::Retail => [InventoryTrackingMode::FinishedStock, InventoryTrackingMode::None],
        };
    }
}
