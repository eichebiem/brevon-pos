<?php

namespace App\Http\Controllers;

use App\Domain\Sales\PosCatalog;
use App\Domain\Shifts\ShiftService;
use App\Enums\OrderType;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cashier screen (FR-023–FR-025, FR-028, FR-029). Products, prices and
 * stock come from the location of the cashier's open shift; without a
 * shift the screen only offers to open one.
 */
class PosController extends Controller
{
    public function index(Request $request, ShiftService $shifts, PosCatalog $catalog): Response
    {
        /** @var User $user */
        $user = $request->user();
        $shift = $shifts->currentFor($user);

        return Inertia::render('pos/Index', [
            'canOpenShift' => $user->can('shifts.open'),
            'canSell' => $user->can('pos.create-sale') && $user->can('payments.record'),
            'canVerifyEwallet' => $user->can('payments.verify-ewallet'),
            'catalog' => $shift === null ? null : $catalog->for($shift->branch),
            'paymentMethods' => $shift === null ? [] : PaymentMethod::query()->where('is_active', true)->orderBy('id')->get()
                ->map(fn (PaymentMethod $m) => [
                    'code' => $m->code,
                    'name' => $m->name,
                    'type' => $m->type->value,
                    'requires_reference' => $m->requires_reference,
                ])->all(),
            'orderTypes' => array_map(fn (OrderType $t) => ['value' => $t->value, 'label' => $t->label(), 'needs_pickup' => $t->needsPickupTime()], OrderType::cases()),
        ]);
    }
}
