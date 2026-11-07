<?php

namespace App\Domain\Refunds;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\OrderItem;

/**
 * What is left to give back on one order line (D-151). `share` is the
 * line's part of what the customer paid: its total after discount, so the
 * shares of all lines add up to the order total exactly.
 */
final readonly class RefundableLine
{
    public function __construct(
        public OrderItem $item,
        public Quantity $sold,
        public Quantity $refunded,
        public Money $share,
        public Money $refundedAmount,
        public ?int $stockItemId,
    ) {}

    public function remainingQuantity(): Quantity
    {
        return $this->sold->subtract($this->refunded);
    }

    public function remainingAmount(): Money
    {
        return $this->share->subtract($this->refundedAmount);
    }

    /** Ready-made goods can go back on the shelf; made drinks cannot (UC-005 step 52). */
    public function canReturnToStock(): bool
    {
        return $this->stockItemId !== null;
    }

    /**
     * The amount for giving back `$quantity` of what is left, rounded to the
     * centavo (half up). Giving back everything left returns exactly the
     * amount left, so partial refunds always add up to the line (invariant 8).
     */
    public function amountFor(Quantity $quantity): Money
    {
        $left = self::milli($this->remainingQuantity());
        if ($left <= 0) {
            return Money::zero();
        }
        $q = self::milli($quantity);
        $minor = $this->remainingAmount()->minor();

        return Money::fromMinor(intdiv(2 * $minor * $q + $left, 2 * $left));
    }

    private static function milli(Quantity $quantity): int
    {
        return (int) bcmul($quantity->toString(), '1000', 0);
    }
}
