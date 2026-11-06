<?php

namespace App\Models;

use App\Enums\RefundKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Money given back on a sale (FR-042, FR-043, BR-005; D-150–D-155): a
 * reversal record linked to the original order and payment, which are never
 * edited or deleted. Written once; never changed.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_id
 * @property int $branch_id
 * @property int|null $cashier_shift_id
 * @property string $refund_number
 * @property RefundKind $kind
 * @property string $amount
 * @property string $refund_method
 * @property string|null $reference_number
 * @property string $reason
 * @property string $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable $created_at
 */
class Refund extends Model
{
    public const PROCESSED = 'processed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A refund is never changed.'));
        static::deleting(fn () => throw new LogicException('A refund is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<RefundItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class)->orderBy('id');
    }
}
