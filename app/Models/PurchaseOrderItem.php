<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $inventory_item_id
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $unit_cost
 * @property string $line_total
 */
class PurchaseOrderItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Lines of a draft are replaced as a whole; afterwards only the received quantity moves.
        static::updating(function (PurchaseOrderItem $line) {
            if (array_diff(array_keys($line->getDirty()), ['quantity_received', 'updated_at']) !== []) {
                throw new LogicException('Purchase order lines cannot be changed once saved.');
            }
        });
        static::deleting(function (PurchaseOrderItem $line) {
            if ($line->order?->status->value !== 'draft') {
                throw new LogicException('Lines can only be removed from a draft purchase order.');
            }
        });
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
