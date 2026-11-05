<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Locations\LocationContext;
use App\Domain\Sales\OrderReceipt;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shifts\ShiftService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS order history, order detail and receipts (FR-033, FR-034). Route
 * middleware: permission:orders.view. A user only sees orders of locations
 * they are assigned to, closed pop-ups included. Printing a receipt again
 * never creates anything except an audit entry.
 */
class OrderController extends Controller
{
    public function index(Request $request, ShiftService $shifts, LocationContext $locations): Response
    {
        /** @var User $user */
        $user = $request->user();
        $shift = $shifts->currentFor($user);
        $location = $shift !== null ? $shift->branch : $locations->current($user);

        $validated = $request->validate([
            'scope' => ['nullable', 'in:shift,today'],
            'search' => ['nullable', 'string', 'max:50'],
        ]);
        $scope = $shift === null ? 'today' : ($validated['scope'] ?? 'shift');
        $shiftId = $shift?->id;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;

        $orders = $location === null ? collect() : Order::query()
            ->where('branch_id', $location->id)
            ->when($scope === 'shift', fn ($q) => $q->where('cashier_shift_id', $shiftId))
            ->when($scope === 'today', fn ($q) => $q->whereDate('business_date', BusinessClock::today()->toDateString()))
            ->when($search, fn ($q, $s) => $q->where('order_number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $s).'%'))
            ->with(['payments.method:id,name', 'creator:id,name'])
            ->withCount('items')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'created_at' => $o->created_at->toIso8601String(),
                'order_type' => $o->order_type->label(),
                'status' => $o->status->value,
                'payment_status' => $o->payment_status->value,
                'grand_total' => $o->grand_total,
                'items_count' => $o->items_count,
                'payment_method' => $o->payments->first()?->method?->name,
                'cashier' => $o->creator?->name,
            ]);

        return Inertia::render('pos/Orders', [
            'site' => $location === null ? null : ['code' => $location->code, 'name' => $location->name],
            'hasShift' => $shift !== null,
            'scope' => $scope,
            'search' => $search,
            'orders' => $orders->values()->all(),
        ]);
    }

    public function show(Request $request, Order $order, OrderReceipt $receipt): Response
    {
        $this->authorizeView($request, $order);

        return Inertia::render('pos/OrderShow', ['receipt' => $receipt->for($order)]);
    }

    /** The printable 80 mm receipt. `?auto=1` prints at once (right after a sale). */
    public function receipt(Request $request, Order $order, OrderReceipt $receipt): Response
    {
        $this->authorizeView($request, $order);

        return Inertia::render('pos/Receipt', [
            'receipt' => $receipt->for($order),
            'autoPrint' => $request->boolean('auto'),
        ]);
    }

    /** FR-034: a reprint is logged, and nothing else is created. */
    public function reprint(Request $request, Order $order, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeView($request, $order);
        /** @var User $user */
        $user = $request->user();

        $audit->record('order.receipt_reprinted', $order, null, ['order_number' => $order->order_number], $order->branch_id, $user);

        return back();
    }

    private function authorizeView(Request $request, Order $order): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->branches()->whereKey($order->branch_id)->exists(), 404);
    }
}
