<?php

namespace App\Models;

use App\Enums\ProductionBatchStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A production batch (FR-067–FR-070; D-142). Stock moves only when it is
 * posted; posted and cancelled batches never change.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $batch_number
 * @property int $product_id
 * @property int $recipe_id
 * @property string $planned_quantity
 * @property string $actual_quantity
 * @property string $rejected_quantity
 * @property string|null $reject_reason
 * @property CarbonImmutable $produced_at
 * @property ProductionBatchStatus $status
 * @property string|null $notes
 * @property string|null $unit_cost
 * @property int $created_by
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable $created_at
 */
class ProductionBatch extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ProductionBatchStatus::class,
            'produced_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProductionBatch $batch) {
            if ($batch->getOriginal('status') !== ProductionBatchStatus::Draft) {
                throw new LogicException('A posted or cancelled batch cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Production batches are never deleted; cancel them.'));
    }

    /**
     * @return HasMany<ProductionBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionBatchItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
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
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
