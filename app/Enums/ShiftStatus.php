<?php

namespace App\Enums;

/**
 * Cashier shift lifecycle. Phase 6 adds manager review of closed shifts.
 */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
