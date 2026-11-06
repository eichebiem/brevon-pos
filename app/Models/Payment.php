<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A payment on an order (FR-037). `amount` is the amount applied to the
 * order; for cash the tendered amount and change are kept beside it and the
 * change is never revenue (D-038, BR-006). Never deleted (BR-004); refunds
 * are separate records (Phase 9).
 *
 * @property int $id
 * @property int $order_id
 * @property int $payment_method_id
 * @property string $amount
 * @property string|null $amount_tendered
 * @property string $change_amount
 * @property string|null $reference_number
 * @property PaymentStatus $status
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property int $recorded_by
 * @property CarbonImmutable|null $paid_at
 */
class Payment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'verified_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // FR-043: what was paid is never rewritten; only its status (and who checked it) moves.
        static::updating(function (Payment $payment) {
            if (array_diff(array_keys($payment->getDirty()), ['status', 'verified_by', 'verified_at', 'updated_at']) !== []) {
                throw new LogicException('A payment record is never rewritten; record a refund instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payments are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
