<?php

namespace App\Domain\Refunds;

use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;

/**
 * What the order page shows about refunds (UC-005 step 48): the earlier
 * refunds and voids, and — for someone allowed to refund — what is left to
 * give back on each line.
 */
class RefundPanel
{
    public function __construct(
        private readonly Refundables $refundables,
        private readonly StoreSettings $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Order $order): array
    {
        return array_values($order->refunds()->with(['items.orderItem', 'requester:id,name', 'approver:id,name', 'payment.method:id,name'])->get()
            ->map(fn (Refund $r) => [
                'refund_number' => $r->refund_number,
                'kind' => $r->kind->value,
                'kind_label' => $r->kind->label(),
                'amount' => $r->amount,
                'method' => $r->payment?->method->name ?? $r->refund_method,
                'reference_number' => $r->reference_number,
                'reason' => $r->reason,
                'by' => $r->requester?->name,
                'approved_by' => $r->approver?->name,
                'at' => ($r->processed_at ?? $r->created_at)->toIso8601String(),
                'items' => $r->items->map(fn (RefundItem $i) => [
                    'name' => $i->orderItem?->product_name_snapshot,
                    'variant' => $i->orderItem?->variant_name_snapshot,
                    'quantity' => $i->quantity,
                    'amount' => $i->amount,
                    'return_to_stock' => $i->return_to_stock,
                ])->all(),
            ])->all());
    }

    /**
     * Null when the user may not refund or nothing can be refunded.
     *
     * @return array<string, mixed>|null
     */
    public function form(User $user, Order $order): ?array
    {
        if (! $user->can('refunds.request') || $order->status !== OrderStatus::Completed) {
            return null;
        }
        /** @var Payment|null $payment */
        $payment = $order->payments()->with('method')->get()
            ->first(fn (Payment $p) => in_array($p->status, [PaymentStatus::Verified, PaymentStatus::PartiallyRefunded], true));
        if ($payment === null) {
            return null;
        }

        $lines = $this->refundables->for($order);
        $refunded = Money::sum(array_map(fn (RefundableLine $l) => $l->refundedAmount, $lines));
        $isCash = $payment->method?->type === PaymentMethodType::Cash;

        return [
            'method' => $payment->method?->name,
            'is_cash' => $isCash,
            'requires_reference' => ! $isCash && (bool) $payment->method?->requires_reference,
            'has_drawer' => CashierShift::query()->open()->where('user_id', $user->id)->where('branch_id', $order->branch_id)->exists(),
            'can_approve' => $user->can('refunds.approve'),
            'approval_over' => $this->settings->money('refund.approval_over')->toDecimal(),
            'refunded' => $refunded->toDecimal(),
            'lines' => array_map(fn (RefundableLine $l) => [
                'id' => $l->item->id,
                'name' => $l->item->product_name_snapshot,
                'variant' => $l->item->variant_name_snapshot,
                'sold' => $l->sold->toString(),
                'remaining_quantity' => $l->remainingQuantity()->toString(),
                'remaining_amount' => $l->remainingAmount()->toDecimal(),
                'can_return_to_stock' => $l->canReturnToStock(),
            ], $lines),
        ];
    }
}
