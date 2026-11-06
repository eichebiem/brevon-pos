<?php

namespace App\Domain\Discounts;

use App\Domain\Sales\PricedLine;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Enums\BeneficiaryType;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Models\Discount;
use App\Models\OrderDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Works out a discount for priced cart lines (FR-083; D-145–D-147).
 *
 * Percentage: base × value ÷ 100, half-up to the centavo. Fixed: the value,
 * never more than the base. The base is the lines the discount covers: all
 * lines (order scope; for SC/PWD the lines the beneficiary is having), the
 * product's lines, or the category's lines. Errors use the `discount` key.
 */
class DiscountCalculator
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function apply(DiscountRequest $request, array $lines): AppliedDiscount
    {
        $discount = Discount::query()->find($request->discountId);
        if ($discount === null || ! $discount->is_active) {
            throw ValidationException::withMessages(['discount' => __('This discount is not available.')]);
        }
        $now = BusinessClock::now();
        if (($discount->starts_at !== null && $discount->starts_at->greaterThan($now)) || ($discount->ends_at !== null && $discount->ends_at->lessThanOrEqualTo($now))) {
            throw ValidationException::withMessages(['discount' => __(':name is not valid today.', ['name' => $discount->name])]);
        }
        if ($this->usedUp($discount)) {
            throw ValidationException::withMessages(['discount' => __(':name has been used up.', ['name' => $discount->name])]);
        }

        $indexes = $this->eligible($discount, $request, $lines);
        if ($indexes === []) {
            throw ValidationException::withMessages(['discount' => __('No item in this order qualifies for :name.', ['name' => $discount->name])]);
        }

        $base = Money::sum(array_map(fn (int $i) => $lines[$i]->lineTotal, $indexes));
        $amount = $discount->discount_type === DiscountType::Percentage
            ? self::percentOf($base, $discount->value)
            : Money::fromDecimal($discount->value);
        if ($amount->greaterThan($base)) {
            $amount = $base;
        }
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['discount' => __('No item in this order qualifies for :name.', ['name' => $discount->name])]);
        }

        return new AppliedDiscount($discount, $base, $amount, $indexes);
    }

    /** max_uses counts orders that were not cancelled (D-145). Lock the discount row first when it matters. */
    public function usedUp(Discount $discount): bool
    {
        if ($discount->max_uses === null) {
            return false;
        }

        $used = OrderDiscount::query()->where('discount_id', $discount->id)
            ->whereHas('order', fn (Builder $q) => $q->where('status', '!=', OrderStatus::Cancelled))
            ->count();

        return $used >= $discount->max_uses;
    }

    /**
     * D-148: every SC/PWD discount records who it was for.
     *
     * @return array{type: BeneficiaryType, full_name: string, card_number: string, address: string}
     */
    public function beneficiary(?BeneficiaryInput $input): array
    {
        $type = BeneficiaryType::tryFrom((string) $input?->type);
        $name = preg_replace('/\s+/', ' ', trim((string) $input?->fullName)) ?? '';
        $card = trim((string) $input?->cardNumber);
        $address = trim((string) $input?->address);

        $errors = [];
        if ($type === null) {
            $errors['discount.beneficiary.type'] = __('Choose senior citizen or PWD.');
        }
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150 || preg_match('/\p{L}.*\s.*\p{L}/u', $name) !== 1) {
            $errors['discount.beneficiary.name'] = __('Enter the full name as shown on the ID.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/]{2,39}$/', $card) !== 1) {
            $errors['discount.beneficiary.card_number'] = __('Enter the ID card number (3–40 letters, digits or dashes).');
        }
        if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
            $errors['discount.beneficiary.address'] = __('Enter the address (5–255 characters).');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        /** @var BeneficiaryType $type */

        return ['type' => $type, 'full_name' => $name, 'card_number' => strtoupper($card), 'address' => $address];
    }

    /**
     * Spread an amount over lines in proportion to their weights, to the
     * centavo, giving leftover centavos to the largest remainders so the
     * parts always add up to the whole.
     *
     * @param  array<int, Money>  $weights  keyed by line index
     * @return array<int, Money>
     */
    public static function allocate(Money $amount, array $weights): array
    {
        $total = array_sum(array_map(fn (Money $w) => $w->minor(), $weights));
        $parts = [];
        if ($total <= 0) {
            foreach ($weights as $i => $w) {
                $parts[$i] = Money::zero();
            }

            return $parts;
        }

        $shares = [];
        $remainders = [];
        $given = 0;
        foreach ($weights as $i => $w) {
            $exact = intdiv($amount->minor() * $w->minor(), $total);
            $shares[$i] = $exact;
            $given += $exact;
            $remainders[$i] = ($amount->minor() * $w->minor()) % $total;
        }
        $left = $amount->minor() - $given;
        if ($left > 0) {
            arsort($remainders);
            foreach (array_keys($remainders) as $i) {
                if ($left === 0) {
                    break;
                }
                $shares[$i]++;
                $left--;
            }
        }
        foreach ($shares as $i => $minor) {
            $parts[$i] = Money::fromMinor($minor);
        }

        return $parts;
    }

    /** base × rate ÷ 100, half-up to the centavo; rate has up to 4 decimal places. */
    public static function percentOf(Money $base, string $rate): Money
    {
        if (! is_numeric($rate)) {
            throw new \InvalidArgumentException("Not a rate: {$rate}");
        }
        $exact = bcdiv(bcmul((string) $base->minor(), $rate, 6), '100', 6);

        return Money::fromMinor((int) bcadd($exact, '0.5', 0));
    }

    /**
     * @param  list<PricedLine>  $lines
     * @return list<int>
     */
    private function eligible(Discount $discount, DiscountRequest $request, array $lines): array
    {
        $all = array_keys($lines);

        return match ($discount->scope) {
            DiscountScope::Order => $discount->isSeniorPwd() && $request->lineIndexes !== null
                ? $this->chosen($request->lineIndexes, $all)
                : $all,
            DiscountScope::Product => array_values(array_filter($all, fn (int $i) => $lines[$i]->product->id === $discount->product_id)),
            DiscountScope::Category => array_values(array_filter($all, fn (int $i) => $lines[$i]->product->category_id === $discount->category_id)),
        };
    }

    /**
     * @param  list<int>  $chosen
     * @param  list<int>  $all
     * @return list<int>
     */
    private function chosen(array $chosen, array $all): array
    {
        $chosen = array_values(array_unique($chosen));
        sort($chosen);
        if (array_diff($chosen, $all) !== []) {
            throw ValidationException::withMessages(['discount.lines' => __('Choose the items the senior citizen or PWD is having.')]);
        }

        return $chosen;
    }
}
