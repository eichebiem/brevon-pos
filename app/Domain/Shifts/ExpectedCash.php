<?php

namespace App\Domain\Shifts;

use App\Domain\Shared\Money;
use App\Models\CashierShift;

/**
 * Expected drawer cash for a shift (FR-077, BR-008, D-042).
 *
 * Phase 4a: only the opening float exists. Phase 4b adds cash payments
 * (applied amount, net of change); Phase 6 adds cash-in, cash-out, paid-outs,
 * cash refunds and approved corrections. GCash never counts (BR-007).
 */
class ExpectedCash
{
    public function for(CashierShift $shift): Money
    {
        return Money::fromDecimal($shift->opening_cash);
    }
}
