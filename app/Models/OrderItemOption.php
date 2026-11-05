<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An option chosen for an order line, with name and price snapshots.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int|null $option_id
 * @property string $option_group_name_snapshot
 * @property string $option_name_snapshot
 * @property string $price_delta_snapshot
 */
class OrderItemOption extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Order item options are never deleted.'));
    }
}
