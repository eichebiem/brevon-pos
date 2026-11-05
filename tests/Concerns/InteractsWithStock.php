<?php

namespace Tests\Concerns;

use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryItemType;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;

trait InteractsWithStock
{
    /** A finished doughnut's stock item (linked to a finished-stock product, counted in pieces). */
    protected function doughnutItem(): InventoryItem
    {
        /** @var InventoryItem */
        return Product::factory()->finishedStock()->create()->inventoryItem;
    }

    /** An ingredient counted by weight, not linked to any product. */
    protected function ingredientItem(string $sku = 'ING-FLOUR'): InventoryItem
    {
        $unit = UnitOfMeasure::query()->firstOrCreate(['code' => 'g'], ['name' => 'Gram', 'precision' => 3]);

        return InventoryItem::query()->create([
            'sku' => $sku, 'name' => 'Flour', 'item_type' => InventoryItemType::Ingredient, 'unit_of_measure_id' => $unit->id,
        ]);
    }

    protected function onHand(Branch $location, InventoryItem $item): string
    {
        return (string) (BranchInventory::query()->where(['branch_id' => $location->id, 'inventory_item_id' => $item->id])->value('quantity_on_hand') ?? '0.000');
    }

    protected function postStock(User $actor, Branch $location, InventoryItem $item, string $quantity, InventoryMovementType $type = InventoryMovementType::Opening): void
    {
        app(InventoryLedger::class)->post($actor, [new StockChange($location->id, $item->id, $type, Quantity::fromString($quantity))]);
    }
}
