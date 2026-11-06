<?php

namespace App\Enums;

/** Purchase order states (UC-006; D-136). Received and cancelled are final. */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Ordered',
            self::PartiallyReceived => 'Partly received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Deliveries can still be received against it. */
    public function isOpen(): bool
    {
        return $this === self::Submitted || $this === self::PartiallyReceived;
    }
}
