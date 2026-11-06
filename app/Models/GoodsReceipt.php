<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A delivery that was received (UC-006; D-137), with or without a purchase
 * order. Immutable: mistakes are corrected with a stock adjustment.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $purchase_order_id
 * @property int|null $supplier_id
 * @property string $receipt_number
 * @property string|null $supplier_delivery_reference
 * @property CarbonImmutable $received_at
 * @property int $received_by
 * @property int|null $over_receipt_approved_by
 * @property string|null $notes
 */
class GoodsReceipt extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Goods receipts cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Goods receipts are never deleted.'));
    }

    /**
     * @return HasMany<GoodsReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overReceiptApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'over_receipt_approved_by');
    }
}
