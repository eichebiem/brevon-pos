<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Refunds\RefundService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens to an order after checkout (FR-041, FR-046–FR-052; D-045,
 * D-123–D-125): one-step status changes, cancellation with a reason, and
 * checking GCash payments. Every change locks the order row, so two people
 * tapping at once cannot both apply; the second sees what the first did.
 */
class OrderWorkflow
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly RefundService $refunds,
    ) {}

    /**
     * Moves the order one step forward. `$from` is the status the person
     * saw on screen: if someone else already moved it, nothing changes.
     */
    public function advance(User $actor, Order $order, OrderStatus $from): Order
    {
        return DB::transaction(function () use ($actor, $order, $from) {
            $order = $this->lock($order);
            $this->assertStillAt($order, $from);

            $to = $from->next();
            if ($to === null || $from === OrderStatus::Pending) {
                throw ValidationException::withMessages(['status' => __('Order :number cannot move on from :status.', ['number' => $order->order_number, 'status' => $from->label()])]);
            }

            $order->forceFill([
                'status' => $to,
                'completed_at' => $to === OrderStatus::Completed ? now() : $order->completed_at,
            ])->save();
            $this->history($order, $from, $to, $actor);

            return $order;
        });
    }

    /**
     * Cancels an order that is not yet completed (FR-052, D-123). Orders are
     * paid in full at checkout (D-043), so the whole payment is marked
     * refunded: cash goes back from the drawer that took it, so that shift
     * must still be open. Stock is put back only when the person says the
     * goods can be sold again (UC-005 step 52).
     */
    public function cancel(User $actor, Order $order, string $reason, bool $returnStock): Order
    {
        return DB::transaction(function () use ($actor, $order, $reason, $returnStock) {
            // Same lock order as checkout: shift first, then the order.
            $shift = $order->cashier_shift_id === null ? null
                : CashierShift::query()->whereKey($order->cashier_shift_id)->lockForUpdate()->first();
            $order = $this->lock($order);

            if (! $order->status->isCancellable()) {
                throw ValidationException::withMessages(['reason' => __('Order :number is :status and cannot be cancelled. Completed orders are refunded instead.', ['number' => $order->order_number, 'status' => strtolower($order->status->label())])]);
            }

            /** @var list<Payment> $payments */
            $payments = $order->payments()->with('method')->lockForUpdate()->get()
                ->filter(fn (Payment $p) => in_array($p->status, [PaymentStatus::Verified, PaymentStatus::Pending], true))
                ->values()->all();

            $cashBack = array_filter($payments, fn (Payment $p) => $p->method?->type === PaymentMethodType::Cash);
            if ($cashBack !== [] && ($shift === null || ! $shift->isOpen())) {
                throw ValidationException::withMessages(['reason' => __('The shift that took this cash payment is closed, so the cash cannot come back out of that drawer. Refund the order instead.')]);
            }

            foreach ($payments as $payment) {
                $old = $payment->status;
                $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
                $this->audit->record('payment.refunded', $payment, ['status' => $old->value], [
                    'status' => PaymentStatus::Refunded->value,
                    'amount' => $payment->amount,
                    'method' => $payment->method?->name,
                    'reason' => 'Order cancelled',
                ], $order->branch_id, $actor);
            }

            // D-154: each payment handed back gets a void record, so voids and
            // refunds are reported from one place (BR-005).
            $voids = array_map(fn (Payment $p) => $this->refunds->recordVoid($actor, $order, $p, $reason, $returnStock)->refund_number, $payments);

            $returned = $returnStock ? $this->returnStock($actor, $order, $reason) : [];

            $from = $order->status;
            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'payment_status' => $payments === [] ? $order->payment_status : OrderPaymentStatus::Refunded,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();
            $this->history($order, $from, OrderStatus::Cancelled, $actor, $reason);

            $this->audit->record('order.cancelled', $order, ['status' => $from->value], [
                'status' => OrderStatus::Cancelled->value,
                'reason' => $reason,
                'refunded' => array_map(fn (Payment $p) => ['method' => $p->method?->name, 'amount' => $p->amount], $payments),
                'stock_returned' => $returned,
                'void_numbers' => $voids,
            ], $order->branch_id, $actor);

            return $order;
        });
    }

    /** BR-019: a manager confirms the GCash transfer arrived (D-124). */
    public function verifyPayment(User $actor, Payment $payment): Payment
    {
        return DB::transaction(function () use ($actor, $payment) {
            [$order, $payment] = $this->lockPayment($payment);

            $payment->forceFill(['status' => PaymentStatus::Verified, 'verified_by' => $actor->id, 'verified_at' => now()])->save();

            $stillPending = $order->payments()->where('status', PaymentStatus::Pending)->exists();
            if (! $stillPending) {
                $order->forceFill(['payment_status' => OrderPaymentStatus::Paid])->save();
            }

            $this->audit->record('payment.verified', $payment, ['status' => PaymentStatus::Pending->value], [
                'status' => PaymentStatus::Verified->value,
                'reference_number' => $payment->reference_number,
                'amount' => $payment->amount,
            ], $order->branch_id, $actor);

            return $payment;
        });
    }

    /**
     * The transfer never arrived (D-124): the payment fails and the order
     * is left unpaid, so the loss shows in reports instead of disappearing.
     */
    public function rejectPayment(User $actor, Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($actor, $payment, $reason) {
            [$order, $payment] = $this->lockPayment($payment);

            $payment->forceFill(['status' => PaymentStatus::Failed, 'verified_by' => $actor->id, 'verified_at' => now()])->save();
            $order->forceFill([
                'payment_status' => OrderPaymentStatus::Unpaid,
                'amount_paid' => '0.00',
                'amount_due' => $order->grand_total,
            ])->save();

            $this->audit->record('payment.rejected', $payment, ['status' => PaymentStatus::Pending->value], [
                'status' => PaymentStatus::Failed->value,
                'reference_number' => $payment->reference_number,
                'amount' => $payment->amount,
                'reason' => $reason,
            ], $order->branch_id, $actor);

            return $payment;
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function lockPayment(Payment $payment): array
    {
        $order = $this->lock(Order::query()->findOrFail($payment->order_id));
        $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($payment->status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages(['payment' => __('This payment was already checked.')]);
        }
        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['payment' => __('Order :number was cancelled.', ['number' => $order->order_number])]);
        }

        return [$order, $payment];
    }

    private function assertStillAt(Order $order, OrderStatus $from): void
    {
        if ($order->status !== $from) {
            throw ValidationException::withMessages(['status' => __('Order :number is already :status.', ['number' => $order->order_number, 'status' => strtolower($order->status->label())])]);
        }
    }

    private function history(Order $order, OrderStatus $from, OrderStatus $to, User $actor, ?string $notes = null): void
    {
        $row = new OrderStatusHistory;
        $row->forceFill(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'changed_by' => $actor->id, 'notes' => $notes, 'created_at' => now()])->save();
    }

    /**
     * Reverses exactly the stock the sale took (its `sale` movements).
     *
     * @return list<array{item_id: int, quantity: string}>
     */
    private function returnStock(User $actor, Order $order, string $reason): array
    {
        /** @var array<int, Quantity> $perItem */
        $perItem = [];
        InventoryMovement::query()
            ->where('reference_type', 'order')->where('reference_id', $order->id)
            ->where('movement_type', InventoryMovementType::Sale)
            ->get()
            ->each(function (InventoryMovement $m) use (&$perItem) {
                $perItem[$m->inventory_item_id] = ($perItem[$m->inventory_item_id] ?? Quantity::zero())
                    ->add(Quantity::fromString($m->quantity_delta)->negate());
            });

        if ($perItem === []) {
            return [];
        }

        $changes = [];
        foreach ($perItem as $itemId => $quantity) {
            $changes[] = new StockChange($order->branch_id, $itemId, InventoryMovementType::ReturnIn, $quantity, reason: __('Order :number cancelled: :reason', ['number' => $order->order_number, 'reason' => $reason]), reference: $order);
        }

        $this->ledger->post($actor, $changes);

        return array_map(fn (int $id, Quantity $q) => ['item_id' => $id, 'quantity' => $q->toString()], array_keys($perItem), array_values($perItem));
    }
}
