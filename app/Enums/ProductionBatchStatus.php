<?php

namespace App\Enums;

/** Production batch states (FR-067; D-142). Posted and cancelled are final. */
enum ProductionBatchStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
