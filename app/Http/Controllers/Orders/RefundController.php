<?php

namespace App\Http\Controllers\Orders;

use App\Domain\Refunds\RefundRequest;
use App\Domain\Refunds\RefundService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Refunds of completed sales (FR-042–FR-045, UC-005; D-150–D-155). Route
 * middleware: permission:refunds.request. Orders of a location the user
 * is not assigned to answer 404 (FR-104).
 */
class RefundController extends Controller
{
    public function store(Request $request, Order $order, RefundService $refunds): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->worksAt($order->branch_id), 404);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999'],
            'lines.*.return_to_stock' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'seen_refunded' => ['required', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'reference' => ['nullable', 'string', 'max:40'],
            'approver_username' => ['nullable', 'string', 'max:50'],
            'approver_password' => ['nullable', 'string', 'max:200'],
        ]);

        $lines = [];
        foreach ($validated['lines'] as $line) {
            $lines[(int) $line['order_item_id']] = [
                'quantity' => Quantity::fromString((string) $line['quantity']),
                'return_to_stock' => (bool) $line['return_to_stock'],
            ];
        }

        $refund = $refunds->refund($user, $order, new RefundRequest(
            $lines,
            trim((string) $validated['reason']),
            Money::fromDecimal((string) $validated['seen_refunded']),
            isset($validated['reference']) ? (string) $validated['reference'] : null,
            isset($validated['approver_username']) ? (string) $validated['approver_username'] : null,
            isset($validated['approver_password']) ? (string) $validated['approver_password'] : null,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund :number: :amount given back.', ['number' => $refund->refund_number, 'amount' => Money::fromDecimal($refund->amount)->format()])]);

        return back();
    }
}
