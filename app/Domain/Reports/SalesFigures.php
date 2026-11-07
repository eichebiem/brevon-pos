<?php

namespace App\Domain\Reports;

use App\Domain\Shared\Money;

/**
 * One row of sales figures, worked out the same way everywhere (FR-097,
 * SRS §9, D-156):
 *
 *  - gross sales: line totals before discounts (price × quantity, add-ons included);
 *  - discounts: each line's share of the order discount;
 *  - refunds: what was given back on these lines (sale-date view);
 *  - net sales: gross − discounts − refunds;
 *  - orders: orders with at least one of these lines; average = net ÷ orders.
 */
final readonly class SalesFigures
{
    public function __construct(
        public Money $gross,
        public Money $discounts,
        public Money $refunds,
        public int $orders,
        public string $quantity,
    ) {}

    public static function fromRow(object $row): self
    {
        $money = fn (mixed $v) => Money::fromDecimal(is_numeric($v) ? (string) $v : '0');

        return new self(
            $money($row->gross ?? null),
            $money($row->discounts ?? null),
            $money($row->refunds ?? null),
            (int) ($row->orders ?? 0),
            is_numeric($row->quantity ?? null) ? (string) $row->quantity : '0',
        );
    }

    public function net(): Money
    {
        return $this->gross->subtract($this->discounts)->subtract($this->refunds);
    }

    /** Net sales ÷ orders, half up to the centavo. */
    public function average(): Money
    {
        if ($this->orders === 0) {
            return Money::zero();
        }
        $net = $this->net()->minor();
        $sign = $net < 0 ? -1 : 1;

        return Money::fromMinor($sign * intdiv(2 * abs($net) + $this->orders, 2 * $this->orders));
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'gross' => $this->gross->toDecimal(),
            'discounts' => $this->discounts->toDecimal(),
            'refunds' => $this->refunds->toDecimal(),
            'net' => $this->net()->toDecimal(),
            'orders' => $this->orders,
            'average' => $this->average()->toDecimal(),
            'quantity' => rtrim(rtrim($this->quantity, '0'), '.') ?: '0',
        ];
    }
}
