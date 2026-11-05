<?php

namespace App\Domain\Sales;

use RuntimeException;

/** The idempotency key was already used for a different checkout request (D-026 → HTTP 409). */
class IdempotencyConflict extends RuntimeException
{
    public function __construct(public readonly string $orderNumber)
    {
        parent::__construct("This checkout key was already used for order {$orderNumber} with different details.");
    }
}
