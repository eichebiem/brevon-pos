<?php

namespace App\Domain\Discounts;

use App\Domain\Shared\Money;
use App\Models\Discount;

/** A discount worked out for a cart: the amount and the lines it covers. */
final readonly class AppliedDiscount
{
    /**
     * @param  list<int>  $lineIndexes
     */
    public function __construct(
        public Discount $discount,
        public Money $base,
        public Money $amount,
        public array $lineIndexes,
    ) {}
}
