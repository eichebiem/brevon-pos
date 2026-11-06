<?php

namespace App\Domain\Discounts;

/** SC/PWD details typed at the counter (D-068). Validated by DiscountCalculator::beneficiary(). */
final readonly class BeneficiaryInput
{
    public function __construct(
        public ?string $type,
        public ?string $fullName,
        public ?string $cardNumber,
        public ?string $address,
    ) {}
}
