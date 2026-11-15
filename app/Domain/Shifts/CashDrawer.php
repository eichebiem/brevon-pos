<?php

namespace App\Domain\Shifts;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Enums\CashMovementType;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes a drawer's cash outside a sale (FR-076, FR-080,
 * FR-082; D-126–D-130). Every action locks the shift row first — the same
 * lock checkout and closing take — so a movement can never slip in while
 * the shift is being closed.
 */
class CashDrawer
{
    private const MAX = '999999.99';

    public function __construct(
        private readonly ExpectedCash $expected,
        private readonly AuditLogger $audit,
        private readonly ShiftSummary $summary,
    ) {}

    /**
     * Cash in, cash out or a paid-out on an open shift (FR-076). The
     * cashier records on their own shift; someone who reviews shifts may
     * record on any shift at their location. Every movement counts at once;
     * approval of large cash out was removed (D-193, future feature FE-05).
     */
    public function record(User $actor, CashierShift $shift, CashMovementType $type, Money $amount, string $reason): CashMovement
    {
        if (! in_array($type, CashMovementType::manual(), true)) {
            throw ValidationException::withMessages(['movement_type' => __('Choose cash in, cash out or paid out.')]);
        }
        $this->assertAmount($amount, 'amount');
        $reviewer = $actor->can('shifts.review');
        if ($shift->user_id !== $actor->id && ! $reviewer) {
            throw ValidationException::withMessages(['amount' => __('You can only record cash on your own shift.')]);
        }

        return DB::transaction(function () use ($actor, $shift, $type, $amount, $reason) {
            $shift = $this->lockOpen($shift, 'amount');

            if ($type->isOutflow()) {
                $this->assertDrawerCovers($shift, $amount);
            }

            $movement = new CashMovement;
            $movement->forceFill([
                'cashier_shift_id' => $shift->id,
                'branch_id' => $shift->branch_id,
                'movement_type' => $type,
                'amount' => $amount->toDecimal(),
                'reason' => $reason,
                'created_by' => $actor->id,
                'created_at' => now(),
            ])->save();

            $this->audit->record('cash_movement.recorded', $movement, null, [
                'shift' => $shift->shift_number,
                'type' => $type->value,
                'amount' => $movement->amount,
                'reason' => $reason,
            ], $shift->branch_id, $actor);

            return $movement;
        });
    }

    /**
     * FR-082: a closed shift is never edited; a reviewer adds a correction
     * to its expected cash (a missed paid-out, say) or its counted cash (a
     * miscount), signed, with a reason (D-129). Nobody corrects a shift they
     * worked themselves.
     */
    public function correct(User $actor, CashierShift $shift, string $corrects, Money $amount, string $reason): CashMovement
    {
        if (! in_array($corrects, ['expected', 'counted'], true)) {
            throw ValidationException::withMessages(['corrects' => __('Choose what is being corrected.')]);
        }
        if ($amount->isZero()) {
            throw ValidationException::withMessages(['amount' => __('Enter the amount to add (or a minus amount to take away).')]);
        }
        $this->assertAmount($amount->absolute(), 'amount');
        if ($shift->user_id === $actor->id) {
            throw ValidationException::withMessages(['amount' => __('Someone else must correct a shift you worked.')]);
        }

        return DB::transaction(function () use ($actor, $shift, $corrects, $amount, $reason) {
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->isOpen()) {
                throw ValidationException::withMessages(['amount' => __('The shift is still open. Record a cash movement instead.')]);
            }

            $movement = new CashMovement;
            $movement->forceFill([
                'cashier_shift_id' => $shift->id,
                'branch_id' => $shift->branch_id,
                'movement_type' => CashMovementType::Correction,
                'amount' => $amount->toDecimal(),
                'corrects' => $corrects,
                'reason' => $reason,
                'approved_by' => $actor->id,
                'created_by' => $actor->id,
                'created_at' => now(),
            ])->save();

            $this->audit->record('shift.corrected', $shift, null, [
                'corrects' => $corrects,
                'amount' => $movement->amount,
                'reason' => $reason,
            ], $shift->branch_id, $actor);

            return $movement;
        });
    }

    /**
     * FR-080: a manager signs off a closed shift once (D-128). A shift with
     * a difference needs a note; nobody reviews a shift they worked.
     */
    public function review(User $actor, CashierShift $shift, ?string $notes): CashierShift
    {
        if ($shift->user_id === $actor->id) {
            throw ValidationException::withMessages(['review_notes' => __('Someone else must review a shift you worked.')]);
        }

        return DB::transaction(function () use ($actor, $shift, $notes) {
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->isOpen()) {
                throw ValidationException::withMessages(['review_notes' => __('Close the shift before reviewing it.')]);
            }
            if ($shift->reviewed_at !== null) {
                throw ValidationException::withMessages(['review_notes' => __('This shift was already reviewed.')]);
            }
            $variance = $this->summary->correctedVariance($shift);
            if (! $variance->isZero() && ($notes === null || trim($notes) === '')) {
                throw ValidationException::withMessages(['review_notes' => __('Explain how the :amount difference was handled.', ['amount' => $variance->format()])]);
            }

            $shift->forceFill(['approved_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes])->save();

            $this->audit->record('shift.reviewed', $shift, null, [
                'variance' => $variance->toDecimal(),
                'notes' => $notes,
            ], $shift->branch_id, $actor);

            return $shift;
        });
    }

    /**
     * Cash handed back on a refund (FR-077, D-153): an approved cash refund
     * movement on the refunder's open shift, linked to the refund. The caller
     * has already locked the shift row inside its transaction.
     */
    public function giveRefund(User $actor, CashierShift $shift, Money $amount, Refund $refund): CashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['refund' => __('Shift :number is closed.', ['number' => $shift->shift_number])]);
        }
        $this->assertDrawerCovers($shift, $amount, 'refund');

        $movement = new CashMovement;
        $movement->forceFill([
            'cashier_shift_id' => $shift->id,
            'branch_id' => $shift->branch_id,
            'movement_type' => CashMovementType::Refund,
            'amount' => $amount->toDecimal(),
            'reason' => __('Refund :number', ['number' => $refund->refund_number]),
            'reference_type' => $refund->getMorphClass(),
            'reference_id' => $refund->id,
            'approved_by' => $refund->approved_by,
            'created_by' => $actor->id,
            'created_at' => now(),
        ])->save();

        return $movement;
    }

    private function lockOpen(CashierShift $shift, string $field): CashierShift
    {
        $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([$field => __('Shift :number is closed.', ['number' => $shift->shift_number])]);
        }

        return $shift;
    }

    private function assertAmount(Money $amount, string $field): void
    {
        if (! $amount->isPositive() || $amount->greaterThan(Money::fromDecimal(self::MAX))) {
            throw ValidationException::withMessages([$field => __('Enter an amount from ₱0.01 to ₱999,999.99.')]);
        }
    }

    /** Cash cannot leave a drawer that does not hold it (expected cash must cover the amount). */
    private function assertDrawerCovers(CashierShift $shift, Money $amount, string $field = 'amount'): void
    {
        $available = $this->expected->for($shift);

        if ($amount->greaterThan($available)) {
            throw ValidationException::withMessages([$field => __('The drawer should only hold :available, so :amount cannot come out of it.', ['available' => $available->format(), 'amount' => $amount->format()])]);
        }
    }
}
