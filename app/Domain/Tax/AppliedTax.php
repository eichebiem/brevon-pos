<?php

namespace App\Domain\Tax;

use App\Domain\Shared\Money;
use App\Models\TaxRule;

/** One tax rule worked out for an order. */
final readonly class AppliedTax
{
    public function __construct(
        public TaxRule $rule,
        public Money $taxable,
        public Money $amount,
    ) {}
}
