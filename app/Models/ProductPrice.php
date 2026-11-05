<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Price history row (FR-019). Rows are append-only: the only permitted
 * change is closing an open row by setting effective_until (D-087).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int|null $branch_id
 * @property string $price
 * @property string|null $cost_price
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class ProductPrice extends Model
{
    protected $fillable = ['product_id', 'variant_id', 'branch_id', 'price', 'cost_price', 'effective_from', 'effective_until', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProductPrice $price) {
            $dirty = array_keys($price->getDirty());
            $closing = $dirty === ['effective_until'] && $price->getOriginal('effective_until') === null;

            if (! $closing) {
                throw new LogicException('Price history is append-only; only an open price can be closed.');
            }
        });

        static::deleting(fn () => throw new LogicException('Price history cannot be deleted.'));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
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
}
