<?php

namespace App\Enums;

/** Order lifecycle (D-044, D-045). Transitions after checkout arrive in Phase 5. */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
