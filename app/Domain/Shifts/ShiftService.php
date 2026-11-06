<?php

namespace App\Domain\Shifts;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Domain\Shared\NumberSequence;
use App\Enums\CashMovementStatus;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Terminal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening and closing cashier shifts (FR-073, FR-074, FR-078, FR-079, FR-107).
 *
 * A shift is opened on a terminal; its location is the terminal's location,
 * never request input (D-060). One open shift per cashier and per terminal,
 * enforced here and by unique indexes (D-027, D-094). A shift can always be
 * closed, even after its pop-up has ended (D-059).
 */
class ShiftService
{
    public function __construct(
        private readonly NumberSequence $numbers,
        private readonly ExpectedCash $expectedCash,
        private readonly AuditLogger $audit,
    ) {}

    public function currentFor(User $user): ?CashierShift
    {
        return CashierShift::query()->open()->where('user_id', $user->id)->with(['branch', 'terminal'])->first();
    }

    /**
     * Active terminals the user may open a shift on today: at assigned
     * locations that can operate today, with no shift already open.
     *
     * @return Collection<int, Terminal>
     */
    public function availableTerminals(User $user): Collection
    {
        $today = BusinessClock::today();
        $locationIds = $user->branches()->where('branches.is_active', true)->get()
            ->filter(fn (Branch $branch) => $branch->acceptsNewOperationsOn($today))
            ->pluck('id');

        return Terminal::query()
            ->whereIn('branch_id', $locationIds)
            ->where('is_active', true)
            ->whereDoesntHave('shifts', fn ($q) => $q->where('status', ShiftStatus::Open))
            ->with('branch')
            ->orderBy('branch_id')->orderBy('terminal_code')
            ->get();
    }

    public function open(User $cashier, Terminal $terminal, Money $openingCash): CashierShift
    {
        if ($openingCash->isNegative()) {
            throw ValidationException::withMessages(['opening_cash' => __('Opening cash cannot be negative.')]);
        }

        try {
            return DB::transaction(function () use ($cashier, $terminal, $openingCash) {
                // Lock the terminal so its location and status cannot change underneath us.
                /** @var Terminal $terminal */
                $terminal = Terminal::query()->whereKey($terminal->id)->lockForUpdate()->firstOrFail();
                /** @var Branch $location */
                $location = $terminal->branch()->firstOrFail();
                $today = BusinessClock::today();

                $this->assertCanOpen($cashier, $terminal, $location, $today);

                $sequence = $this->numbers->next($location, 'shift', $today);
                $shift = new CashierShift;
                $shift->forceFill([
                    'branch_id' => $location->id,
                    'terminal_id' => $terminal->id,
                    'user_id' => $cashier->id,
                    'shift_number' => sprintf('%s-%s-S%02d', $location->code, $today->format('Ymd'), $sequence),
                    'business_date' => $today->toDateString(),
                    'opened_at' => now(),
                    'opening_cash' => $openingCash->toDecimal(),
                    'status' => ShiftStatus::Open,
                ])->save();

                $this->audit->record('shift.opened', $shift, null, [
                    'shift_number' => $shift->shift_number,
                    'terminal' => $terminal->terminal_code,
                    'opening_cash' => $shift->opening_cash,
                ], $location->id, $cashier);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another request: the indexes are the final guard.
            throw ValidationException::withMessages(['terminal_id' => __('A shift was just opened for this cashier or terminal. Refresh and try again.')]);
        }
    }

    /**
     * Records the counted cash and variance (FR-078, FR-079). The cashier
     * closes their own shift; someone with shifts.review may close anyone's.
     */
    public function close(User $actor, CashierShift $shift, Money $countedCash, ?string $notes): CashierShift
    {
        if ($shift->user_id !== $actor->id && ! $actor->can('shifts.review')) {
            throw ValidationException::withMessages(['counted_cash' => __('You can only close your own shift.')]);
        }

        if ($countedCash->isNegative()) {
            throw ValidationException::withMessages(['counted_cash' => __('Counted cash cannot be negative.')]);
        }

        return DB::transaction(function () use ($actor, $shift, $countedCash, $notes) {
            /** @var CashierShift $shift */
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $shift->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => __('This shift is already closed.')]);
            }

            // D-127: every cash movement must be decided before the drawer is counted.
            if (CashMovement::query()->where('cashier_shift_id', $shift->id)->where('status', CashMovementStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['counted_cash' => __('A cash movement is still waiting for approval. Ask a manager to approve or reject it first.')]);
            }

            $expected = $this->expectedCash->for($shift);
            $variance = $countedCash->subtract($expected);

            // D-128: the count is blind. A difference is never shown to the
            // cashier; they recount or explain it in the closing notes.
            if (! $variance->isZero() && ($notes === null || trim($notes) === '')) {
                throw ValidationException::withMessages(['closing_notes' => __('The count does not match the drawer. Count the cash again, or explain the difference in the notes.')]);
            }

            $shift->forceFill([
                'status' => ShiftStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'expected_cash' => $expected->toDecimal(),
                'counted_cash' => $countedCash->toDecimal(),
                'variance' => $variance->toDecimal(),
                'closing_notes' => $notes,
            ])->save();

            $this->audit->record('shift.closed', $shift, ['status' => 'open'], [
                'status' => 'closed',
                'expected_cash' => $shift->expected_cash,
                'counted_cash' => $shift->counted_cash,
                'variance' => $shift->variance,
            ], $shift->branch_id, $actor);

            return $shift;
        });
    }

    private function assertCanOpen(User $cashier, Terminal $terminal, Branch $location, CarbonImmutable $today): void
    {
        if (! $cashier->branches()->whereKey($location->id)->exists()) {
            throw ValidationException::withMessages(['terminal_id' => __('You are not assigned to :location.', ['location' => $location->name])]);
        }

        if (! $terminal->is_active) {
            throw ValidationException::withMessages(['terminal_id' => __('This terminal is inactive.')]);
        }

        // FR-107, D-059: no new shifts at an inactive pop-up or outside its operating dates.
        if (! $location->acceptsNewOperationsOn($today)) {
            throw ValidationException::withMessages(['terminal_id' => __(':location is not open for business today.', ['location' => $location->name])]);
        }

        if (CashierShift::query()->open()->where('user_id', $cashier->id)->exists()) {
            throw ValidationException::withMessages(['terminal_id' => __('You already have an open shift. Close it before opening another.')]);
        }

        if (CashierShift::query()->open()->where('terminal_id', $terminal->id)->exists()) {
            throw ValidationException::withMessages(['terminal_id' => __('Another cashier already has a shift open on this terminal.')]);
        }
    }
}
