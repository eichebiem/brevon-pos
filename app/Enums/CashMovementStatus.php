<?php

namespace App\Enums;

/** Only approved movements count toward expected cash (D-127). */
enum CashMovementStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
