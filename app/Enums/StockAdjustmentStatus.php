<?php

namespace App\Enums;

/** Stock adjustment states (D-131). Posted and rejected are final. */
enum StockAdjustmentStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Posted => 'Posted',
            self::Rejected => 'Rejected',
        };
    }
}
