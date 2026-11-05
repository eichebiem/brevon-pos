<?php

namespace App\Domain\Shifts;

use App\Domain\Shared\Money;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\Payment;

/**
 * Expected drawer cash for a shift (FR-077, BR-008, D-042):
 * opening float + cash payments on the shift's orders (the amount applied,
 * so change handed back is already excluded, BR-006).
 *
 * GCash and other non-cash payments never count (BR-007). Phase 6 adds
 * cash-in, cash-out, paid-outs, cash refunds and approved corrections.
 */
class ExpectedCash
{
    public function for(CashierShift $shift): Money
    {
        return Money::fromDecimal($shift->opening_cash)->add($this->cashSales($shift));
    }

    public function cashSales(CashierShift $shift): Money
    {
        $amounts = Payment::query()
            ->where('status', PaymentStatus::Verified)
            ->whereHas('method', fn ($q) => $q->where('type', PaymentMethodType::Cash))
            ->whereHas('order', fn ($q) => $q->where('cashier_shift_id', $shift->id))
            ->pluck('amount');

        return Money::sum($amounts->map(fn (string $amount) => Money::fromDecimal($amount)));
    }
}
