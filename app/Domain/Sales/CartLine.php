<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Quantity;

/** One cart line as sent by the POS. Prices are never taken from the client (BR-003). */
final readonly class CartLine
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public Quantity $quantity,
        public ?string $notes = null,
    ) {}
}
