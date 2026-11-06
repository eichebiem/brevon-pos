<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Discounts\DiscountApproval;
use App\Domain\Sales\CartPricer;
use App\Domain\Sales\CheckoutService;
use App\Domain\Sales\IdempotencyConflict;
use App\Domain\Shared\Money;
use App\Domain\Shifts\ShiftService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * POS checkout endpoints (Phase 4b). The cashier screen (Phase 4c) posts
 * the cart here; the confirmation screen and receipt come in 4c.
 */
class CheckoutController extends Controller
{
    /** Server-side totals for the cart on screen; nothing is saved (FR-026). */
    public function quote(CheckoutRequest $request, CartPricer $pricer, ShiftService $shifts, DiscountApproval $approvals): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shift = $shifts->currentFor($user);

        if ($shift === null) {
            throw ValidationException::withMessages(['shift' => __('Open a shift before taking payments.')]);
        }

        $cart = $pricer->price($shift->branch, $request->lines(), $request->discount());
        $tendered = $request->validated('payment.amount_tendered');
        $tendered = $tendered === null ? null : Money::fromDecimal((string) $tendered);

        // Change preview for the cash dialog; the browser never does money arithmetic (D-034).
        return response()->json([
            ...$cart->toArray(),
            // Whether a manager has to approve the discount before payment (D-146).
            'discount_needs_approval' => $cart->discount !== null && $approvals->needsApproval($user, $cart->discount->discount, $cart->discount->amount),
            'change' => $tendered === null || $tendered->lessThan($cart->grandTotal) ? null : $tendered->subtract($cart->grandTotal)->toDecimal(),
        ]);
    }

    public function store(CheckoutRequest $request, CheckoutService $checkout): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $checkout->checkout($user, $request->toCheckoutData());
        } catch (IdempotencyConflict $e) {
            abort(409, $e->getMessage());
        }

        Inertia::flash('lastOrder', self::summary($result->order));
        Inertia::flash('toast', ['type' => 'success', 'message' => $result->replayed
            ? __('Order :number was already completed.', ['number' => $result->order->order_number])
            : __('Order :number completed.', ['number' => $result->order->order_number])]);

        return to_route('pos.index');
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        $payment = $order->payments()->with('method')->firstOrFail();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'grand_total' => $order->grand_total,
            'payment_method' => $payment->method?->name,
            'amount_tendered' => $payment->amount_tendered,
            'change_amount' => $payment->change_amount,
            'reference_number' => $payment->reference_number,
        ];
    }
}
