<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A doughnut dispatch from the main branch to a pop-up (D-063, D-133).
 * Posted when saved; never edited or deleted — a mistake is corrected with
 * a stock adjustment at the location concerned.
 *
 * @property int $id
 * @property string $transfer_number
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $created_at
 */
class StockTransfer extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Dispatches cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Dispatches are never deleted.'));
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
