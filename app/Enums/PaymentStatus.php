<?php

namespace App\Enums;

/** State of one payment record (SRS §8.4). Cash is verified on receipt; GCash waits for a manual check (BR-019). */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Money that was received: confirmed payments, including ones later
     * refunded in part or in full by a refund record (D-153). Whether a
     * refunded payment still counts depends on its order not being
     * cancelled — a cancellation hands the payment itself back.
     *
     * @return list<self>
     */
    public static function taken(): array
    {
        return [self::Verified, self::PartiallyRefunded, self::Refunded];
    }
}
