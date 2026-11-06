<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Shared\Money;
use App\Domain\Shifts\ShiftService;
use App\Domain\Shifts\ShiftSummary;
use App\Enums\CashMovementType;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The cashier's own shift: open on a terminal, then close with a cash count (FR-073–FR-079). */
class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly ShiftSummary $summary,
    ) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->shifts->currentFor($user);

        return Inertia::render('pos/Shift', [
            'shift' => $current ? self::present($current) : null,
            // The count stays blind (D-128): movements are listed, expected cash is not.
            'movements' => $current ? $this->summary->movements($current) : [],
            'movementTypes' => array_map(fn (CashMovementType $t) => ['value' => $t->value, 'label' => $t->label()], CashMovementType::manual()),
            'canRecordCash' => $user->can('cash-movements.create'),
            'terminals' => $current ? [] : $this->shifts->availableTerminals($user)->map(fn (Terminal $t) => [
                'id' => $t->id,
                'code' => $t->terminal_code,
                'name' => $t->name,
                'location' => $t->branch?->name,
                'location_code' => $t->branch?->code,
            ])->values(),
            'recent' => CashierShift::query()->where('user_id', $user->id)->where('status', 'closed')
                ->with(['branch:id,code,name', 'terminal:id,terminal_code'])
                ->latest('closed_at')->limit(5)->get()
                ->map(fn (CashierShift $s) => self::present($s)),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'opening_cash' => ['required', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $shift = $this->shifts->open($user, Terminal::query()->findOrFail((int) $data['terminal_id']), Money::fromDecimal((string) $data['opening_cash']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift :number opened.', ['number' => $shift->shift_number])]);

        return to_route('staff.shift');
    }

    public function close(Request $request, CashierShift $shift): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $shift = $this->shifts->close($user, $shift, Money::fromDecimal((string) $data['counted_cash']), $data['closing_notes'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift :number closed.', ['number' => $shift->shift_number])]);

        // A manager closing someone else's forgotten shift goes back to its review page.
        return $shift->user_id === $user->id ? to_route('staff.shift') : to_route('admin.shifts.show', $shift);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CashierShift $shift): array
    {
        return [
            'id' => $shift->id,
            'number' => $shift->shift_number,
            'status' => $shift->status->value,
            'location' => $shift->branch?->name,
            'location_code' => $shift->branch?->code,
            'terminal' => $shift->terminal?->terminal_code,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_cash' => $shift->opening_cash,
            'expected_cash' => $shift->expected_cash,
            'counted_cash' => $shift->counted_cash,
            'variance' => $shift->variance,
        ];
    }
}
