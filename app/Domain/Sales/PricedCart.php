<?php

namespace App\Domain\Sales;

use App\Domain\Discounts\AppliedDiscount;
use App\Domain\Shared\Money;

/**
 * Server-calculated totals (FR-026, BR-003). Grand total = subtotal −
 * discount; no tax is charged (non-VAT business, D-165). Per-line
 * discounts add up to the order discount exactly.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<Money>  $lineDiscounts  same order as $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $grandTotal,
        public ?AppliedDiscount $discount = null,
        public array $lineDiscounts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line, int $i) => [
                'product_id' => $line->product->id,
                'variant_id' => $line->variant?->id,
                'name' => $line->product->name,
                'variant' => $line->variant?->name,
                'quantity' => $line->quantity->toString(),
                'unit_price' => $line->unitPrice->toDecimal(),
                'line_total' => $line->lineTotal->toDecimal(),
                'discount_amount' => ($this->lineDiscounts[$i] ?? Money::zero())->toDecimal(),
            ], $this->lines, array_keys($this->lines)),
            'subtotal' => $this->subtotal->toDecimal(),
            'discount_total' => $this->discountTotal->toDecimal(),
            'discount' => $this->discount === null ? null : [
                'id' => $this->discount->discount->id,
                'name' => $this->discount->discount->name,
                'lines' => $this->discount->lineIndexes,
                'amount' => $this->discount->amount->toDecimal(),
            ],
            'grand_total' => $this->grandTotal->toDecimal(),
        ];
    }
}
