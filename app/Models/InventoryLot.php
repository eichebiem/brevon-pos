<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A received lot of a lot-tracked item (FR-060; D-138). Only the remaining
 * quantity changes, and only through InventoryLedger, which uses it up
 * first-expiring-first as stock leaves.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $inventory_item_id
 * @property string|null $lot_number
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $expires_at
 * @property string $quantity_received
 * @property string $quantity_remaining
 * @property string|null $unit_cost
 * @property int|null $supplier_id
 */
class InventoryLot extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'expires_at' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (InventoryLot $lot) {
            if (array_diff(array_keys($lot->getDirty()), ['quantity_remaining', 'updated_at']) !== []) {
                throw new LogicException('Only the remaining quantity of a lot changes.');
            }
        });
        static::deleting(fn () => throw new LogicException('Lots are never deleted.'));
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }
}
