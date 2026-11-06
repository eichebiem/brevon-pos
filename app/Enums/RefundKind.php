<?php

namespace App\Enums;

/**
 * A refund of a completed sale, or the void record a cancellation writes
 * (D-150, D-154).
 */
enum RefundKind: string
{
    case Refund = 'refund';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::Void => 'Void',
        };
    }
}
