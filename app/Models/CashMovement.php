<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Cash put into or taken out of a shift's drawer (FR-076), or a correction
 * to a closed shift (FR-082). Append-only: only a pending row may be
 * decided (approved or rejected) once; nothing is ever deleted.
 *
 * @property int $id
 * @property int $cashier_shift_id
 * @property int $branch_id
 * @property CashMovementType $movement_type
 * @property string $amount
 * @property string|null $corrects
 * @property string $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable $created_at
 */
class CashMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'movement_type' => CashMovementType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are never changed.'));
        static::deleting(fn () => throw new LogicException('Cash movements are never deleted.'));
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
