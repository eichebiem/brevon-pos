<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Locations\LocationContext;
use App\Domain\Orders\OrderWorkflow;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GCash payments waiting for a manager to confirm the transfer arrived
 * (BR-019, FR-039, D-124). Route middleware: permission:payments.verify-ewallet.
 */
class PaymentCheckController extends Controller
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function index(Request $request, LocationContext $locations): Response
    {
        /** @var User $user */
        $user = $request->user();
        $location = $locations->current($user);

        $payments = $location === null ? collect() : Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->whereHas('order', fn ($q) => $q->where('branch_id', $location->id))
            ->with(['order:id,order_number,status,created_by,created_at', 'order.creator:id,name', 'method:id,name'])
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'order_id' => $p->order_id,
                'order_number' => $p->order?->order_number,
                'order_status' => $p->order?->status->value,
                'created_at' => $p->order?->created_at->toIso8601String(),
                'cashier' => $p->order?->creator?->name,
                'method' => $p->method?->name,
                'amount' => $p->amount,
                'reference_number' => $p->reference_number,
            ]);

        return Inertia::render('payments/Pending', [
            'site' => $location === null ? null : ['code' => $location->code, 'name' => $location->name],
            'payments' => $payments->values()->all(),
        ]);
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $user = $this->userFor($request, $payment);
        $this->workflow->verifyPayment($user, $payment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference :ref confirmed.', ['ref' => $payment->reference_number])]);

        return back();
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $user = $this->userFor($request, $payment);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->workflow->rejectPayment($user, $payment, trim((string) $validated['reason']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference :ref marked as not received. The order is now unpaid.', ['ref' => $payment->reference_number])]);

        return back();
    }

    private function userFor(Request $request, Payment $payment): User
    {
        /** @var User $user */
        $user = $request->user();
        $branchId = $payment->order()->value('branch_id');
        abort_unless($branchId !== null && $user->worksAt((int) $branchId), 404);

        return $user;
    }
}
