<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * The discount given on an order (FR-084): snapshots of what was applied,
 * by whom, and who approved it. Written once at checkout; never changed.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $discount_id
 * @property string $discount_name_snapshot
 * @property string|null $discount_code_snapshot
 * @property DiscountType $discount_type_snapshot
 * @property string $discount_value_snapshot
 * @property string $discount_amount
 * @property string|null $reason
 * @property int $applied_by
 * @property int|null $approved_by
 */
class OrderDiscount extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['discount_type_snapshot' => DiscountType::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An order discount is never changed.'));
        static::deleting(fn () => throw new LogicException('An order discount is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasOne<OrderDiscountBeneficiary, $this>
     */
    public function beneficiary(): HasOne
    {
        return $this->hasOne(OrderDiscountBeneficiary::class);
    }
}
