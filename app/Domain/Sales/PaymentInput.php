<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Money;

/**
 * How the customer pays. One payment per order in V1 (split tender is off,
 * D-040). Cash gives `amountTendered`; GCash gives `reference`, and the
 * cashier confirms the transfer arrived before finishing the sale (D-168).
 */
final readonly class PaymentInput
{
    public function __construct(
        public string $methodCode,
        public ?Money $amountTendered = null,
        public ?string $reference = null,
    ) {}
}
