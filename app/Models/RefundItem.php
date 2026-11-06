<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One order line given back in a refund (UC-005): how many, for how much,
 * and whether the goods went back into sellable stock. Never changed.
 *
 * @property int $id
 * @property int $refund_id
 * @property int $order_item_id
 * @property string $quantity
 * @property string $amount
 * @property bool $return_to_stock
 */
class RefundItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['return_to_stock' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A refund line is never changed.'));
        static::deleting(fn () => throw new LogicException('A refund line is never deleted.'));
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
