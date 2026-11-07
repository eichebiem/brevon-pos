<?php

namespace App\Domain\Shifts;

use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Enums\CashMovementStatus;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Payment;

/**
 * The shift summary (UC-008, FR-077–FR-082, D-130): the drawer figures, the
 * corrections added after closing, and non-cash takings reported apart from
 * the drawer (FR-081, BR-007). Corrected figures are always worked out from
 * the closing figures plus correction rows — never stored on the shift.
 */
class ShiftSummary
{
    public function __construct(
        private readonly ExpectedCash $expected,
        private readonly StoreSettings $settings,
    ) {}

    /**
     * @return array{expected: Money, counted: Money}
     */
    public function corrections(CashierShift $shift): array
    {
        $rows = CashMovement::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('movement_type', CashMovementType::Correction)
            ->get(['corrects', 'amount']);

        $sum = fn (string $corrects) => Money::sum($rows->where('corrects', $corrects)->map(fn (CashMovement $m) => Money::fromDecimal($m->amount)));

        return ['expected' => $sum('expected'), 'counted' => $sum('counted')];
    }

    /** Counted minus expected, after corrections. Zero while the shift is open. */
    public function correctedVariance(CashierShift $shift): Money
    {
        if ($shift->isOpen() || $shift->expected_cash === null || $shift->counted_cash === null) {
            return Money::zero();
        }
        $c = $this->corrections($shift);

        return Money::fromDecimal($shift->counted_cash)->add($c['counted'])
            ->subtract(Money::fromDecimal($shift->expected_cash)->add($c['expected']));
    }

    /** Closed, not yet reviewed, and the corrected difference is over the set limit (D-128). */
    public function needsReview(CashierShift $shift): bool
    {
        return ! $shift->isOpen() && $shift->reviewed_at === null
            && $this->correctedVariance($shift)->absolute()->greaterThan($this->settings->money('shift.review_variance_over'));
    }

    /**
     * Full figures for managers. Expected cash is live while the shift is
     * open and the stored closing figure afterwards.
     *
     * @return array<string, mixed>
     */
    public function build(CashierShift $shift): array
    {
        $c = $this->expected->components($shift);
        $expected = $shift->isOpen() || $shift->expected_cash === null
            ? $this->expected->for($shift)
            : Money::fromDecimal($shift->expected_cash);

        $corrections = $this->corrections($shift);
        $counted = $shift->counted_cash === null ? null : Money::fromDecimal($shift->counted_cash);

        $cancelledCash = Money::sum(Payment::query()
            ->where('status', PaymentStatus::Refunded)
            ->whereHas('method', fn ($q) => $q->where('type', PaymentMethodType::Cash))
            ->whereHas('order', fn ($q) => $q->where('cashier_shift_id', $shift->id)->where('status', OrderStatus::Cancelled))
            ->pluck('amount')->map(fn (string $a) => Money::fromDecimal($a)));

        $orders = Order::query()->where('cashier_shift_id', $shift->id);

        return [
            'opening_cash' => $c['opening']->toDecimal(),
            'cash_sales' => $c['cash_sales']->toDecimal(),
            'cancelled_cash' => $cancelledCash->toDecimal(),
            'cash_in' => $c['cash_in']->toDecimal(),
            'cash_out' => $c['cash_out']->toDecimal(),
            'paid_out' => $c['paid_out']->toDecimal(),
            'refunds' => $c['refunds']->toDecimal(),
            'expected_cash' => $expected->toDecimal(),
            'counted_cash' => $counted?->toDecimal(),
            'variance' => $shift->variance,
            'expected_correction' => $corrections['expected']->toDecimal(),
            'counted_correction' => $corrections['counted']->toDecimal(),
            'corrected_expected' => $expected->add($corrections['expected'])->toDecimal(),
            'corrected_counted' => $counted?->add($corrections['counted'])->toDecimal(),
            'corrected_variance' => $counted ? $this->correctedVariance($shift)->toDecimal() : null,
            'non_cash' => $this->nonCash($shift),
            'order_count' => (clone $orders)->count(),
            'cancelled_count' => (clone $orders)->where('status', OrderStatus::Cancelled)->count(),
            'pending_movements' => CashMovement::query()->where('cashier_shift_id', $shift->id)->where('status', CashMovementStatus::Pending)->count(),
            'needs_review' => $this->needsReview($shift),
        ];
    }

    /**
     * Non-cash takings by method (FR-081). GCash is confirmed by the cashier
     * at the sale (D-168), so every payment taken counts.
     *
     * @return list<array{method: string, verified: string}>
     */
    private function nonCash(CashierShift $shift): array
    {
        $payments = Payment::query()
            ->whereIn('status', PaymentStatus::taken())
            ->whereHas('method', fn ($q) => $q->where('type', '!=', PaymentMethodType::Cash))
            ->whereHas('order', fn ($q) => $q->where('cashier_shift_id', $shift->id)->where('status', '!=', OrderStatus::Cancelled))
            ->with('method:id,name')
            ->get(['id', 'payment_method_id', 'amount', 'status']);

        return array_values($payments->groupBy(fn (Payment $p) => $p->method->name)->map(function ($rows, $method) {
            return [
                'method' => (string) $method,
                'verified' => Money::sum($rows->map(fn (Payment $p) => Money::fromDecimal($p->amount)))->toDecimal(),
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function movements(CashierShift $shift): array
    {
        return array_values($shift->cashMovements()->with(['creator:id,name', 'approver:id,name'])->get()
            ->map(fn (CashMovement $m) => [
                'id' => $m->id,
                'type' => $m->movement_type->value,
                'type_label' => $m->movement_type->label(),
                'amount' => $m->amount,
                'corrects' => $m->corrects,
                'reason' => $m->reason,
                'status' => $m->status->value,
                'by' => $m->creator?->name,
                'by_id' => $m->created_by,
                'decided_by' => $m->approver?->name,
                'decision_notes' => $m->decision_notes,
                'at' => $m->created_at->toIso8601String(),
            ])->all());
    }
}
