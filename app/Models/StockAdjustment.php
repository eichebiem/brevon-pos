<?php

namespace App\Models;

use App\Enums\StockAdjustmentKind;
use App\Enums\StockAdjustmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A stock count or adjustment (FR-056, FR-061, BR-012; D-131). Stock moves
 * only when it is posted; a posted or rejected adjustment never changes.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $adjustment_number
 * @property StockAdjustmentKind $kind
 * @property StockAdjustmentStatus $status
 * @property string $reason
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_notes
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable $created_at
 */
class StockAdjustment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => StockAdjustmentKind::class,
            'status' => StockAdjustmentStatus::class,
            'decided_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StockAdjustment $adjustment) {
            $allowed = ['status', 'approved_by', 'decided_at', 'decision_notes', 'posted_at', 'updated_at'];
            if ($adjustment->getOriginal('status') !== StockAdjustmentStatus::Pending
                || array_diff(array_keys($adjustment->getDirty()), $allowed) !== []) {
                throw new LogicException('A decided stock adjustment cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Stock adjustments are never deleted.'));
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
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
}
