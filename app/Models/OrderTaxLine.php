<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Tax charged on an order, per rule, with snapshots (FR-086; D-149).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $tax_rule_id
 * @property string $tax_name_snapshot
 * @property string $tax_rate_snapshot
 * @property bool $inclusive_snapshot
 * @property string $taxable_amount
 * @property string $tax_amount
 */
class OrderTaxLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['inclusive_snapshot' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order tax lines are never changed.'));
        static::deleting(fn () => throw new LogicException('Order tax lines are never deleted.'));
    }
}
