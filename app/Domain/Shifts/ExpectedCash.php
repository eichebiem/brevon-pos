<?php

namespace App\Domain\Shifts;

use App\Domain\Shared\Money;
use App\Enums\CashMovementStatus;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Payment;

/**
 * Expected drawer cash for a shift (FR-077, BR-007, BR-008, D-042, D-126):
 *
 *   opening float + cash sales + cash in − cash out − paid outs − cash refunds
 *
 * Cash sales are verified cash payments on the shift's orders, counted at
 * the amount applied (change handed back is already excluded, BR-006);
 * payments refunded by a cancellation are no longer in the drawer. Only
 * approved movements count; pending ones wait for a manager (D-127).
 * GCash and other non-cash payments never count. Corrections to a closed
 * shift are reported beside the closing figures, never folded in here.
 */
class ExpectedCash
{
    public function for(CashierShift $shift): Money
    {
        $c = $this->components($shift);

        return $c['opening']->add($c['cash_sales'])->add($c['cash_in'])
            ->subtract($c['cash_out'])->subtract($c['paid_out'])->subtract($c['refunds']);
    }

    public function cashSales(CashierShift $shift): Money
    {
        return $this->components($shift)['cash_sales'];
    }

    /**
     * @return array{opening: Money, cash_sales: Money, cash_in: Money, cash_out: Money, paid_out: Money, refunds: Money}
     */
    public function components(CashierShift $shift): array
    {
        $sales = Payment::query()
            ->where('status', PaymentStatus::Verified)
            ->whereHas('method', fn ($q) => $q->where('type', PaymentMethodType::Cash))
            ->whereHas('order', fn ($q) => $q->where('cashier_shift_id', $shift->id))
            ->pluck('amount');

        /** @var array<string, list<string>> $movements */
        $movements = CashMovement::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('status', CashMovementStatus::Approved)
            ->where('movement_type', '!=', CashMovementType::Correction)
            ->get(['movement_type', 'amount'])
            ->groupBy(fn (CashMovement $m) => $m->movement_type->value)
            ->map(fn ($rows) => $rows->pluck('amount')->all())
            ->all();

        $sum = fn (CashMovementType $type) => Money::sum(array_map(fn (string $a) => Money::fromDecimal($a), $movements[$type->value] ?? []));

        return [
            'opening' => Money::fromDecimal($shift->opening_cash),
            'cash_sales' => Money::sum($sales->map(fn (string $amount) => Money::fromDecimal($amount))),
            'cash_in' => $sum(CashMovementType::CashIn),
            'cash_out' => $sum(CashMovementType::CashOut),
            'paid_out' => $sum(CashMovementType::PaidOut),
            'refunds' => $sum(CashMovementType::Refund),
        ];
    }
}
