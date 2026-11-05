<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One stock change (FR-055). Append-only: rows are never updated or deleted;
 * corrections are new movements.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $inventory_item_id
 * @property InventoryMovementType $movement_type
 * @property string $quantity_delta
 * @property string|null $unit_cost
 * @property string $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reason
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['movement_type' => InventoryMovementType::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
