<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a stock adjustment: what the system showed when it was
 * entered, what was counted, and the difference posted (D-131). Only the
 * unit cost is filled in, once, when the adjustment is posted.
 *
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $inventory_item_id
 * @property string $system_quantity
 * @property string $counted_quantity
 * @property string $variance_quantity
 * @property string|null $unit_cost
 */
class StockAdjustmentItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (StockAdjustmentItem $item) {
            if (array_diff(array_keys($item->getDirty()), ['unit_cost', 'updated_at']) !== [] || $item->getOriginal('unit_cost') !== null) {
                throw new LogicException('Stock adjustment lines cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Stock adjustment lines are never deleted.'));
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
