<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $inventory_item_id
 * @property string $quantity_required
 * @property int $unit_of_measure_id
 * @property string $wastage_percent
 */
class RecipeItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recipe lines cannot be changed; save a new version.'));
        static::deleting(fn () => throw new LogicException('Recipe lines are never deleted.'));
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
