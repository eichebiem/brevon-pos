<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $production_batch_id
 * @property int $inventory_item_id
 * @property string $quantity_consumed
 * @property string|null $unit_cost_snapshot
 */
class ProductionBatchItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Lines of a draft are replaced as a whole; posting stamps the cost once.
        static::updating(function (ProductionBatchItem $line) {
            if (array_keys(array_diff_key($line->getDirty(), ['updated_at' => 1])) !== ['unit_cost_snapshot'] || $line->getOriginal('unit_cost_snapshot') !== null) {
                throw new LogicException('Batch lines cannot be changed.');
            }
        });
        static::deleting(function (ProductionBatchItem $line) {
            if ($line->batch?->status->value !== 'draft') {
                throw new LogicException('Lines can only be removed from a draft batch.');
            }
        });
    }

    /**
     * @return BelongsTo<ProductionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
