<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Money;

/**
 * Server-calculated totals (FR-026, BR-003). Discounts arrive in Phase 9
 * (D-036) and no tax rule is active (D-037), so both are zero for now.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $taxTotal,
        public Money $grandTotal,
    ) {}

    /**
     * @return array{lines: list<array<string, mixed>>, subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line) => [
                'product_id' => $line->product->id,
                'variant_id' => $line->variant?->id,
                'name' => $line->product->name,
                'variant' => $line->variant?->name,
                'quantity' => $line->quantity->toString(),
                'unit_price' => $line->unitPrice->toDecimal(),
                'option_total' => $line->optionTotal->toDecimal(),
                'unit_total' => $line->unitPrice->add($line->optionTotal)->toDecimal(),
                'options' => array_map(fn (array $o) => ['id' => $o['option']->id, 'name' => $o['option']->name, 'price_delta' => $o['option']->price_delta], $line->options),
                'line_total' => $line->lineTotal->toDecimal(),
            ], $this->lines),
            'subtotal' => $this->subtotal->toDecimal(),
            'discount_total' => $this->discountTotal->toDecimal(),
            'tax_total' => $this->taxTotal->toDecimal(),
            'grand_total' => $this->grandTotal->toDecimal(),
        ];
    }
}
