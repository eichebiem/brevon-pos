<?php

namespace App\Enums;

/** Payment state of a whole order (ERD §3). */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PendingVerification = 'pending_verification';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
