<?php

namespace App\Models;

use App\Enums\ReceiptCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $purchase_order_item_id
 * @property int $inventory_item_id
 * @property int|null $inventory_lot_id
 * @property string $quantity_received
 * @property string|null $unit_cost
 * @property ReceiptCondition $condition
 * @property string|null $notes
 */
class GoodsReceiptItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['condition' => ReceiptCondition::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Goods receipt lines cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Goods receipt lines are never deleted.'));
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }
}
