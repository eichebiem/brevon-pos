<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One order line. Name, SKU, variant and prices are snapshots taken at
 * checkout, so later catalog changes never rewrite history (FR-022, BR-015).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string $sku_snapshot
 * @property string $product_name_snapshot
 * @property string|null $variant_name_snapshot
 * @property string $product_type_snapshot
 * @property string $quantity
 * @property string $unit_price
 * @property string $option_total
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Order items are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return HasMany<RefundItem, $this>
     */
    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /**
     * @return HasMany<OrderItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('id');
    }
}
