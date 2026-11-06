<?php

namespace App\Http\Controllers\Orders;

use App\Domain\Orders\OrderWorkflow;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Status changes after checkout (FR-046, FR-048, FR-052; D-045, D-123).
 * Routes: `advance` needs orders.update-status, `cancel` needs orders.cancel.
 * Orders of a location the user is not assigned to answer 404 (FR-104).
 */
class OrderStatusController extends Controller
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function advance(Request $request, Order $order): RedirectResponse
    {
        $user = $this->userFor($request, $order);
        $validated = $request->validate([
            'from' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        $order = $this->workflow->advance($user, $order, OrderStatus::from((string) $validated['from']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number: :status.', ['number' => $order->order_number, 'status' => strtolower($order->status->label())])]);

        return back();
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $user = $this->userFor($request, $order);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'return_stock' => ['required', 'boolean'],
        ]);

        $order = $this->workflow->cancel($user, $order, trim((string) $validated['reason']), (bool) $validated['return_stock']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number cancelled and refunded.', ['number' => $order->order_number])]);

        return back();
    }

    private function userFor(Request $request, Order $order): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->worksAt($order->branch_id), 404);

        return $user;
    }
}
