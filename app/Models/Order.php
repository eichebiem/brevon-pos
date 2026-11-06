<?php

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A sale (SRS §8.4). Created only by CheckoutService; never deleted
 * (FR-035, BR-004). No customer data (D-067).
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $terminal_id
 * @property int|null $cashier_shift_id
 * @property string $order_number
 * @property CarbonImmutable $business_date
 * @property OrderType $order_type
 * @property OrderStatus $status
 * @property OrderPaymentStatus $payment_status
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $service_charge
 * @property string $grand_total
 * @property string $amount_paid
 * @property string $amount_due
 * @property string|null $notes
 * @property CarbonImmutable|null $pickup_at
 * @property int $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property string|null $idempotency_key
 * @property string|null $idempotency_request_hash
 * @property CarbonImmutable $created_at
 */
class Order extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'business_date' => 'date',
            'pickup_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Orders are never deleted.'));
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
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
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasOne<OrderDiscount, $this>
     */
    public function discount(): HasOne
    {
        return $this->hasOne(OrderDiscount::class);
    }

    /**
     * @return HasMany<OrderTaxLine, $this>
     */
    public function taxLines(): HasMany
    {
        return $this->hasMany(OrderTaxLine::class);
    }
}
