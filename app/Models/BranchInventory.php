<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-location stock balance: a projection of inventory_movements, written
 * only by InventoryLedger (BR-011).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $inventory_item_id
 * @property string $quantity_on_hand
 * @property string|null $average_unit_cost
 * @property CarbonImmutable|null $updated_at
 */
class BranchInventory extends Model
{
    public const CREATED_AT = null;

    protected $table = 'branch_inventory';

    protected $guarded = ['*'];

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
}
