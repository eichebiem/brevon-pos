<?php

namespace App\Domain\Sales;

use App\Models\Order;

/** The completed order; `replayed` is true when a retry returned an existing order. */
final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public bool $replayed,
    ) {}
}
