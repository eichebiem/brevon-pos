<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested stock change. The quantity is always given as a positive
 * amount; the movement type decides the direction.
 */
final readonly class StockChange
{
    /**
     * @param  string|null  $unitCost  decimal cost per unit for inbound movements (weighted average, D-048); validated by the ledger
     */
    public function __construct(
        public int $branchId,
        public int $inventoryItemId,
        public InventoryMovementType $type,
        public Quantity $quantity,
        public ?string $unitCost = null,
        public ?string $reason = null,
        public ?Model $reference = null,
    ) {}
}
