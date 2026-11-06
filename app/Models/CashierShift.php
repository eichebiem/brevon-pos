<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A cashier's shift on one terminal (FR-073). Location comes from the
 * terminal (D-060). A closed shift is immutable (FR-082): later fixes are
 * correction rows in cash_movements, and only the review stamp is added once.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $terminal_id
 * @property int $user_id
 * @property string $shift_number
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $opened_at
 * @property string $opening_cash
 * @property CarbonImmutable|null $closed_at
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $variance
 * @property ShiftStatus $status
 * @property string|null $closing_notes
 * @property int|null $closed_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_notes
 */
class CashierShift extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CashierShift $shift) {
            if ($shift->getOriginal('status') === ShiftStatus::Open) {
                return;
            }
            // FR-082: a closed shift is never edited. The one exception is the
            // manager's review stamp, given once (D-128); fixes are correction rows.
            $reviewOnly = array_diff(array_keys($shift->getDirty()), ['approved_by', 'reviewed_at', 'review_notes', 'updated_at']) === [];
            if (! $reviewOnly || $shift->getOriginal('reviewed_at') !== null) {
                throw new LogicException('A closed shift cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('Shifts cannot be deleted.'));
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    /**
     * @param  Builder<CashierShift>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ShiftStatus::Open);
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
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
