<?php

namespace App\Domain\Sales;

use App\Domain\Discounts\DiscountRequest;
use App\Domain\Shared\Money;
use App\Enums\OrderType;
use Carbon\CarbonImmutable;

/**
 * A checkout request. `idempotencyKey` is generated once per sale by the POS
 * and resent on retries (FR-031, D-026). `expectedTotal` is the total the
 * cashier showed the customer: if prices changed since, checkout stops
 * instead of charging a different amount.
 */
final readonly class CheckoutData
{
    /**
     * @param  list<CartLine>  $lines
     */
    public function __construct(
        public string $idempotencyKey,
        public OrderType $orderType,
        public array $lines,
        public PaymentInput $payment,
        public ?string $notes = null,
        public ?CarbonImmutable $pickupAt = null,
        public ?Money $expectedTotal = null,
        public ?DiscountRequest $discount = null,
    ) {}

    /** SHA-256 of the normalised request, stored with the order (D-026). */
    public function requestHash(): string
    {
        return hash('sha256', (string) json_encode([
            'order_type' => $this->orderType->value,
            'notes' => $this->notes,
            'pickup_at' => $this->pickupAt?->utc()->toIso8601String(),
            'expected_total' => $this->expectedTotal?->minor(),
            'discount' => $this->discount?->fingerprint(),
            'lines' => array_map(fn (CartLine $l) => [
                $l->productId, $l->variantId, $l->quantity->toString(), $l->optionIds, $l->notes,
            ], $this->lines),
            'payment' => [
                $this->payment->methodCode,
                $this->payment->amountTendered?->minor(),
                $this->payment->reference,
                $this->payment->markVerified,
            ],
        ]));
    }
}
