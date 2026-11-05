<?php

namespace Database\Factories;

use App\Enums\InventoryItemType;
use App\Enums\InventoryTrackingMode;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default: a made-to-order drink with no stock tracking.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('????##')),
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'product_type' => ProductType::MadeToOrder,
            'inventory_tracking_mode' => InventoryTrackingMode::None,
            'base_unit' => 'piece',
            'is_active' => true,
            'is_available_for_pos' => true,
        ];
    }

    /** A doughnut-style finished good linked to its own stock item. */
    public function finishedStock(): static
    {
        return $this->state(function (array $attributes) {
            $unit = UnitOfMeasure::query()->firstOrCreate(['code' => 'pc'], ['name' => 'Piece', 'precision' => 0]);
            $item = InventoryItem::query()->create([
                'sku' => 'INV-'.strtoupper(fake()->unique()->bothify('????##')),
                'name' => 'Stock item',
                'item_type' => InventoryItemType::FinishedGood,
                'unit_of_measure_id' => $unit->id,
            ]);

            return [
                'product_type' => ProductType::FinishedGood,
                'inventory_tracking_mode' => InventoryTrackingMode::FinishedStock,
                'inventory_item_id' => $item->id,
            ];
        });
    }

    public function archived(): static
    {
        return $this->state(['is_active' => false]);
    }
}
