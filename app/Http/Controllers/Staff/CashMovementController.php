<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Shared\Money;
use App\Domain\Shifts\CashDrawer;
use App\Enums\CashMovementStatus;
use App\Enums\CashMovementType;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Cash in, cash out and paid-outs on an open shift, and their approval (FR-076, D-127). */
class CashMovementController extends Controller
{
    public function __construct(private readonly CashDrawer $drawer) {}

    public function store(Request $request, CashierShift $shift): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->worksAt($shift->branch_id), 404);

        $data = $request->validate([
            'movement_type' => ['required', Rule::in(array_map(fn (CashMovementType $t) => $t->value, CashMovementType::manual()))],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $movement = $this->drawer->record($user, $shift, CashMovementType::from($data['movement_type']), Money::fromDecimal((string) $data['amount']), $data['reason']);

        Inertia::flash('toast', $movement->status === CashMovementStatus::Pending
            ? ['type' => 'info', 'message' => __(':type of :amount is waiting for a manager to approve it.', ['type' => $movement->movement_type->label(), 'amount' => Money::fromDecimal($movement->amount)->format()])]
            : ['type' => 'success', 'message' => __(':type of :amount recorded.', ['type' => $movement->movement_type->label(), 'amount' => Money::fromDecimal($movement->amount)->format()])]);

        return back();
    }

    public function approve(Request $request, CashMovement $movement): RedirectResponse
    {
        return $this->decide($request, $movement, true);
    }

    public function reject(Request $request, CashMovement $movement): RedirectResponse
    {
        return $this->decide($request, $movement, false);
    }

    private function decide(Request $request, CashMovement $movement, bool $approve): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->worksAt($movement->branch_id), 404);
        $data = $request->validate(['notes' => [$approve ? 'nullable' : 'required', 'string', 'max:500']]);

        $this->drawer->decide($user, $movement, $approve, $data['notes'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $approve ? __('Cash movement approved.') : __('Cash movement rejected.')]);

        return back();
    }
}
