<?php

namespace App\Domain\Tax;

use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Models\TaxRule;
use Illuminate\Support\Collection;

/**
 * Tax framework (FR-086; D-149). Off until a rule is switched on — that is
 * the accountant's call (BR-018). Every active rule in effect on the
 * business date applies to the order after discounts:
 *
 *  - inclusive (already in the price): tax = taxable × rate ÷ (100 + rate);
 *    the total does not change;
 *  - exclusive (added on top): tax = taxable × rate ÷ 100, added to the total.
 *
 * Each rule is rounded half-up to the centavo once per order.
 */
class TaxCalculator
{
    /**
     * @return Collection<int, TaxRule>
     */
    public function rulesInEffect(): Collection
    {
        $today = BusinessClock::today()->toDateString();

        return TaxRule::query()->where('is_active', true)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->orderBy('id')->get();
    }

    /**
     * @return list<AppliedTax>
     */
    public function apply(Money $taxable): array
    {
        $taxes = [];
        foreach ($this->rulesInEffect() as $rule) {
            $taxes[] = new AppliedTax($rule, $taxable, self::amount($taxable, $rule->rate, $rule->inclusive));
        }

        return $taxes;
    }

    public static function amount(Money $taxable, string $rate, bool $inclusive): Money
    {
        if (! is_numeric($rate)) {
            throw new \InvalidArgumentException("Not a rate: {$rate}");
        }
        if (! $inclusive) {
            return DiscountCalculator::percentOf($taxable, $rate);
        }
        $exact = bcdiv(bcmul((string) $taxable->minor(), $rate, 6), bcadd('100', $rate, 6), 6);

        return Money::fromMinor((int) bcadd($exact, '0.5', 0));
    }
}
