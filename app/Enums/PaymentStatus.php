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
}
