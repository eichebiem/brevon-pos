<?php

namespace App\Domain\Refunds;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

/**
 * What the person at the counter asked to give back (UC-005 step 49).
 * `seenRefunded` is the amount already refunded on the screen they looked
 * at: if someone refunded the order meanwhile, nothing happens and they
 * are told (a double tap cannot refund twice).
 */
final readonly class RefundRequest
{
    /**
     * @param  array<int, array{quantity: Quantity, return_to_stock: bool}>  $lines  keyed by order item id
     */
    public function __construct(
        public array $lines,
        public string $reason,
        public Money $seenRefunded,
        public ?string $reference = null,
        public ?string $approverUsername = null,
        public ?string $approverPassword = null,
    ) {}
}
