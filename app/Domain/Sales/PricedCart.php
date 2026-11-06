<?php

namespace App\Domain\Sales;

use App\Domain\Discounts\AppliedDiscount;
use App\Domain\Shared\Money;
use App\Domain\Tax\AppliedTax;

/**
 * Server-calculated totals (FR-026, BR-003). Grand total = subtotal −
 * discount + tax added on top; tax already in the price is shown but does
 * not change the total (D-149). Per-line discount and tax add up to the
 * order figures exactly.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<Money>  $lineDiscounts  same order as $lines
     * @param  list<Money>  $lineTaxes  same order as $lines
     * @param  list<AppliedTax>  $taxes
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $taxTotal,
        public Money $grandTotal,
        public ?AppliedDiscount $discount = null,
        public array $lineDiscounts = [],
        public array $lineTaxes = [],
        public array $taxes = [],
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
                'option_total' => $line->optionTotal->toDecimal(),
                'unit_total' => $line->unitPrice->add($line->optionTotal)->toDecimal(),
                'options' => array_map(fn (array $o) => ['id' => $o['option']->id, 'name' => $o['option']->name, 'price_delta' => $o['option']->price_delta], $line->options),
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
            'tax_total' => $this->taxTotal->toDecimal(),
            'taxes' => array_map(fn (AppliedTax $t) => [
                'name' => $t->rule->name,
                'rate' => $t->rule->rate,
                'inclusive' => $t->rule->inclusive,
                'amount' => $t->amount->toDecimal(),
            ], $this->taxes),
            'grand_total' => $this->grandTotal->toDecimal(),
        ];
    }
}
