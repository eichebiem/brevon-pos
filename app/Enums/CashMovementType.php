<?php

namespace App\Enums;

/** Drawer cash movements (SRS §8.6, FR-076, BR-008). */
enum CashMovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case PaidOut = 'cash_paid_out';
    case Refund = 'cash_refund';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::CashIn => 'Cash in',
            self::CashOut => 'Cash out',
            self::PaidOut => 'Paid out',
            self::Refund => 'Cash refund',
            self::Correction => 'Correction',
        };
    }

    /** Takes cash out of the drawer. */
    public function isOutflow(): bool
    {
        return in_array($this, [self::CashOut, self::PaidOut, self::Refund], true);
    }

    /**
     * Types staff record by hand on an open shift. Refunds come from the
     * refund workflow (Phase 9); corrections only on closed shifts.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::CashIn, self::CashOut, self::PaidOut];
    }
}
