<?php

namespace App\Models;

use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A preset discount (FR-083; D-145). Switched off, never deleted. Orders
 * keep a snapshot of the name, code, type and value they used (D-146).
 *
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property DiscountKind $kind
 * @property DiscountType $discount_type
 * @property string $value
 * @property DiscountScope $scope
 * @property int|null $product_id
 * @property int|null $category_id
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $max_uses
 * @property bool $requires_approval
 * @property bool $is_active
 */
class Discount extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'discount_type' => DiscountType::class,
            'scope' => DiscountScope::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Discounts are switched off, never deleted.'));
    }

    public function isSeniorPwd(): bool
    {
        return $this->kind === DiscountKind::SeniorPwd;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<OrderDiscount, $this>
     */
    public function uses(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }
}
