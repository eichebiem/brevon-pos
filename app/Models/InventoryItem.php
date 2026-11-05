<?php

namespace App\Models;

use App\Enums\InventoryItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Stock item master (minimal in Phase 3; full UI in Phase 7).
 *
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property InventoryItemType $item_type
 * @property int $unit_of_measure_id
 * @property string $reorder_level
 * @property bool $is_perishable
 * @property bool $track_lot
 * @property bool $is_active
 */
class InventoryItem extends Model
{
    protected $fillable = ['sku', 'name', 'item_type', 'unit_of_measure_id', 'reorder_level', 'is_perishable', 'track_lot', 'is_active'];

    protected function casts(): array
    {
        return [
            'item_type' => InventoryItemType::class,
            'is_perishable' => 'boolean',
            'track_lot' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /**
     * @return HasOne<Product, $this>
     */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class);
    }
}
