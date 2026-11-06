<?php

namespace App\Domain\Shared;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money amount held as integer minor units (centavos).
 *
 * Floats are never accepted: amounts enter as decimal strings (e.g. from
 * DECIMAL(12,2) columns or validated input) or as integer centavos.
 * Rounding is half-up (away from zero) to the centavo.
 *
 * @see docs/DECISIONS.md D-034, SRS NFR-007, prompt §8 invariant 15
 */
final class Money implements JsonSerializable, Stringable
{
    private const DECIMAL_PATTERN = '/^-?\d{1,13}(\.\d{1,2})?$/';

    private function __construct(
        private readonly int $minor,
        private readonly string $currency,
    ) {}

    public static function fromMinor(int $minor, string $currency = 'PHP'): self
    {
        return new self($minor, self::normaliseCurrency($currency));
    }

    /**
     * @param  string  $amount  Decimal string with at most two decimal places, e.g. "125.50".
     */
    public static function fromDecimal(string $amount, string $currency = 'PHP'): self
    {
        $amount = trim($amount);

        if (preg_match(self::DECIMAL_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}]; expected a decimal with at most 2 places.");
        }

        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
        $minor = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return new self($negative ? -$minor : $minor, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency = 'PHP'): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiply by a decimal quantity (e.g. "2", "0.5", "1.250") and round
     * half-up to the centavo. Used for line totals: quantity × unit price.
     */
    public function multiply(Quantity|string $quantity): self
    {
        $factor = $quantity instanceof Quantity ? $quantity->toString() : Quantity::fromString($quantity)->toString();

        $product = bcmul((string) $this->minor, $factor, Quantity::SCALE);

        return new self((int) self::roundHalfUp($product), $this->currency);
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts, string $currency = 'PHP'): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    /**
     * Decimal string suitable for a DECIMAL(12,2) column, e.g. "1234.50".
     */
    public function toDecimal(): string
    {
        $absolute = abs($this->minor);
        $sign = $this->minor < 0 ? '-' : '';

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * Display format, e.g. "₱1,234.50" or "-₱20.00".
     */
    public function format(): string
    {
        $absolute = abs($this->minor);
        $symbol = $this->currency === 'PHP' ? '₱' : $this->currency.' ';
        $sign = $this->minor < 0 ? '-' : '';

        return $sign.$symbol.number_format(intdiv($absolute, 100)).sprintf('.%02d', $absolute % 100);
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    /**
     * Round a bcmath decimal string half-up (away from zero) to an integer string.
     *
     * @param  numeric-string  $value
     */
    private static function roundHalfUp(string $value): string
    {
        return str_starts_with($value, '-')
            ? bcsub($value, '0.5', 0)
            : bcadd($value, '0.5', 0);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: [{$this->currency}] vs [{$other->currency}].");
        }
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$currency}].");
        }

        return $currency;
    }
}
