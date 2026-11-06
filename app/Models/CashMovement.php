<?php

namespace App\Models;

use App\Enums\CashMovementStatus;
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
 * @property CashMovementStatus $status
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_notes
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
            'status' => CashMovementStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CashMovement $movement) {
            $allowed = ['status', 'approved_by', 'decided_at', 'decision_notes'];
            if ($movement->getOriginal('status') !== CashMovementStatus::Pending
                || array_diff(array_keys($movement->getDirty()), $allowed) !== []) {
                throw new LogicException('Cash movements cannot be changed once decided.');
            }
        });
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
