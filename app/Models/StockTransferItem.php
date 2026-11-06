<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $inventory_item_id
 * @property string $quantity
 * @property string|null $unit_cost
 */
class StockTransferItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Dispatch lines cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Dispatch lines are never deleted.'));
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
