<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A purchase order (FR-059, UC-006; D-136). Lines change only while it is
 * a draft; received and cancelled orders never change.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $supplier_id
 * @property string $po_number
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable|null $ordered_at
 * @property CarbonImmutable|null $expected_at
 * @property string $subtotal
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable $created_at
 */
class PurchaseOrder extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_at' => 'datetime',
            'expected_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $order) {
            $was = $order->getOriginal('status');
            if ($was === PurchaseOrderStatus::Received || $was === PurchaseOrderStatus::Cancelled) {
                throw new LogicException('A received or cancelled purchase order cannot be changed.');
            }
            $draftOnly = ['supplier_id', 'expected_at', 'subtotal', 'notes'];
            if ($was !== PurchaseOrderStatus::Draft && array_intersect(array_keys($order->getDirty()), $draftOnly) !== []) {
                throw new LogicException('Only a draft purchase order can be edited.');
            }
        });
        static::deleting(fn () => throw new LogicException('Purchase orders are never deleted; cancel them.'));
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
