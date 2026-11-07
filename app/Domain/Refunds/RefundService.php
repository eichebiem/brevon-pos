<?php

namespace App\Domain\Refunds;

use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shared\Quantity;
use App\Domain\Shifts\CashDrawer;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giving money back on a completed sale (FR-042–FR-045, BR-005, UC-005;
 * D-150–D-155). A refund is a new record linked to the original order and
 * payment; the sale and its payment keep their amounts and only change
 * status. Cash comes out of the drawer of the person giving it back (their
 * open shift at the order's location), e-wallet money is sent back from the
 * shop's account and its reference recorded. Goods go back into stock only
 * when the person says they can be sold again.
 *
 * Lock order: the refunder's shift, the order, its payment, the number
 * sequence, then stock balances — the same order checkout and
 * cancellation use, so they queue instead of deadlocking.
 */
class RefundService
{
    public function __construct(
        private readonly Refundables $refundables,
        private readonly RefundApproval $approvals,
        private readonly NumberSequence $numbers,
        private readonly InventoryLedger $ledger,
        private readonly CashDrawer $drawer,
        private readonly AuditLogger $audit,
    ) {}

    public function refund(User $actor, Order $order, RefundRequest $request): Refund
    {
        if ($request->lines === []) {
            throw ValidationException::withMessages(['lines' => __('Choose what to give back.')]);
        }

        return DB::transaction(function () use ($actor, $order, $request) {
            $shift = CashierShift::query()->open()->where('user_id', $actor->id)->lockForUpdate()->first();
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertRefundable($order);
            $payment = $this->payment($order);

            $lines = [];
            foreach ($this->refundables->for($order) as $line) {
                $lines[$line->item->id] = $line;
            }
            $already = Money::sum(array_map(fn (RefundableLine $l) => $l->refundedAmount, $lines));
            if (! $already->equals($request->seenRefunded)) {
                throw ValidationException::withMessages(['refund' => __('Someone refunded part of this order meanwhile. Check the order again.')]);
            }

            /** @var list<array{line: RefundableLine, quantity: Quantity, amount: Money, return_to_stock: bool}> $chosen */
            $chosen = [];
            foreach ($request->lines as $itemId => $wanted) {
                $line = $lines[$itemId] ?? null;
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => __('That item is not on order :number.', ['number' => $order->order_number])]);
                }
                $name = $line->item->product_name_snapshot.($line->item->variant_name_snapshot ? ' ('.$line->item->variant_name_snapshot.')' : '');
                if (! $wanted['quantity']->isPositive()) {
                    continue;
                }
                if ($wanted['quantity']->compare($line->remainingQuantity()) > 0) {
                    throw ValidationException::withMessages(["lines.{$itemId}" => __('Only :left of :item can still be refunded.', ['left' => self::trim($line->remainingQuantity()->toString()), 'item' => $name])]);
                }
                if ($wanted['return_to_stock'] && ! $line->canReturnToStock()) {
                    throw ValidationException::withMessages(["lines.{$itemId}" => __(':item cannot go back into stock.', ['item' => $name])]);
                }
                $chosen[] = ['line' => $line, 'quantity' => $wanted['quantity'], 'amount' => $line->amountFor($wanted['quantity']), 'return_to_stock' => $wanted['return_to_stock']];
            }
            if ($chosen === []) {
                throw ValidationException::withMessages(['lines' => __('Choose what to give back.')]);
            }

            $amount = Money::sum(array_map(fn (array $c) => $c['amount'], $chosen));
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['lines' => __('Nothing is left to give back on these items.')]);
            }

            /** @var Branch $location */
            $location = $order->branch()->firstOrFail();
            $approver = $this->approvals->resolve($actor, $location, $amount, $request);

            $method = $payment->method;
            $isCash = $method?->type === PaymentMethodType::Cash;
            $reference = null;
            if ($isCash) {
                if ($shift === null || $shift->branch_id !== $order->branch_id) {
                    throw ValidationException::withMessages(['refund' => __('Open a shift at :location to hand the cash back from your drawer.', ['location' => $location->name])]);
                }
            } elseif ($method?->requires_reference) {
                $reference = strtoupper(trim((string) $request->reference));
                if (preg_match('/^[A-Z0-9]{6,40}$/', $reference) !== 1) {
                    throw ValidationException::withMessages(['reference' => __('Enter the reference number of the :method transfer you sent back (6–40 letters or digits).', ['method' => $method->name])]);
                }
            }

            $refund = $this->create($order, $payment, $location, RefundKind::Refund, $amount, $request->reason, $actor, $approver, $isCash ? $shift : null, $reference);
            foreach ($chosen as $c) {
                $this->item($refund, $c['line']->item->id, $c['quantity']->toString(), $c['amount'], $c['return_to_stock']);
            }

            if ($isCash) {
                /** @var CashierShift $shift */
                $this->drawer->giveRefund($actor, $shift, $amount, $refund);
            }

            $returned = $this->returnStock($actor, $order, $refund, $chosen);

            $refundedTotal = $already->add($amount);
            $full = $refundedTotal->greaterThanOrEqual(Money::fromDecimal($payment->amount));
            $oldPayment = $payment->status;
            $payment->forceFill(['status' => $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded])->save();

            $from = $order->status;
            $order->forceFill([
                'payment_status' => $full ? OrderPaymentStatus::Refunded : OrderPaymentStatus::PartiallyRefunded,
                'status' => $full ? OrderStatus::Refunded : $order->status,
            ])->save();
            if ($full) {
                $history = new OrderStatusHistory;
                $history->forceFill(['order_id' => $order->id, 'from_status' => $from, 'to_status' => OrderStatus::Refunded, 'changed_by' => $actor->id, 'notes' => $request->reason, 'created_at' => now()])->save();
            }

            $this->audit->record('refund.processed', $refund, null, [
                'refund_number' => $refund->refund_number,
                'order_number' => $order->order_number,
                'amount' => $refund->amount,
                'method' => $method?->name,
                'reference_number' => $reference,
                'reason' => $request->reason,
                'approved_by' => $approver?->username,
                'items' => array_map(fn (array $c) => ['order_item_id' => $c['line']->item->id, 'quantity' => $c['quantity']->toString(), 'amount' => $c['amount']->toDecimal(), 'return_to_stock' => $c['return_to_stock']], $chosen),
                'stock_returned' => $returned,
            ], $order->branch_id, $actor);
            $this->audit->record('payment.refunded', $payment, ['status' => $oldPayment->value], [
                'status' => $payment->status->value,
                'refund_number' => $refund->refund_number,
                'amount' => $refund->amount,
                'refunded_total' => $refundedTotal->toDecimal(),
                'method' => $method?->name,
            ], $order->branch_id, $actor);

            return $refund;
        });
    }

    private function assertRefundable(Order $order): void
    {
        $error = match ($order->status) {
            OrderStatus::Completed => null,
            OrderStatus::Refunded => __('Order :number was already refunded in full.', ['number' => $order->order_number]),
            OrderStatus::Cancelled => __('Order :number was cancelled, so its payment was already handed back.', ['number' => $order->order_number]),
            default => __('Order :number is not completed, so it cannot be refunded.', ['number' => $order->order_number]),
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['refund' => $error]);
        }
    }

    private function payment(Order $order): Payment
    {
        $payments = $order->payments()->with('method')->lockForUpdate()->get();
        $payment = $payments->first(fn (Payment $p) => in_array($p->status, [PaymentStatus::Verified, PaymentStatus::PartiallyRefunded], true));
        if ($payment instanceof Payment) {
            return $payment;
        }

        throw ValidationException::withMessages(['refund' => __('This order has no payment left to refund.')]);
    }

    private function create(Order $order, Payment $payment, Branch $location, RefundKind $kind, Money $amount, string $reason, User $actor, ?User $approver, ?CashierShift $shift, ?string $reference): Refund
    {
        $today = BusinessClock::today();
        $number = sprintf('%s-%s-R%04d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'refund', $today));

        $refund = new Refund;
        $refund->forceFill([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'branch_id' => $order->branch_id,
            'cashier_shift_id' => $shift?->id,
            'refund_number' => $number,
            'kind' => $kind,
            'amount' => $amount->toDecimal(),
            'refund_method' => (string) $payment->method?->code,
            'reference_number' => $reference,
            'reason' => $reason,
            'status' => Refund::PROCESSED,
            'requested_by' => $actor->id,
            'approved_by' => $approver?->id,
            'processed_at' => now(),
        ])->save();

        return $refund;
    }

    private function item(Refund $refund, int $orderItemId, string $quantity, Money $amount, bool $returnToStock): void
    {
        (new RefundItem)->forceFill([
            'refund_id' => $refund->id,
            'order_item_id' => $orderItemId,
            'quantity' => $quantity,
            'amount' => $amount->toDecimal(),
            'return_to_stock' => $returnToStock,
        ])->save();
    }

    /**
     * @param  list<array{line: RefundableLine, quantity: Quantity, amount: Money, return_to_stock: bool}>  $chosen
     * @return list<array{item_id: int, quantity: string}>
     */
    private function returnStock(User $actor, Order $order, Refund $refund, array $chosen): array
    {
        $perItem = [];
        foreach ($chosen as $c) {
            if (! $c['return_to_stock'] || $c['line']->stockItemId === null) {
                continue;
            }
            $id = $c['line']->stockItemId;
            $perItem[$id] = isset($perItem[$id]) ? $perItem[$id]->add($c['quantity']) : $c['quantity'];
        }
        if ($perItem === []) {
            return [];
        }
        ksort($perItem);

        $changes = [];
        foreach ($perItem as $itemId => $quantity) {
            $changes[] = new StockChange($order->branch_id, $itemId, InventoryMovementType::ReturnIn, $quantity, reason: __('Refund :number on order :order', ['number' => $refund->refund_number, 'order' => $order->order_number]), reference: $refund);
        }
        $this->ledger->post($actor, $changes);

        return array_map(fn (int $id, $q) => ['item_id' => $id, 'quantity' => $q->toString()], array_keys($perItem), array_values($perItem));
    }

    private static function trim(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}
