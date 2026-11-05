<?php

namespace App\Domain\Shared;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable decimal quantity matching DECIMAL(14,3) columns (SRS §8.1).
 * Arithmetic uses bcmath; floats are never accepted.
 */
final class Quantity implements JsonSerializable, Stringable
{
    public const SCALE = 3;

    private const PATTERN = '/^-?\d{1,11}(\.\d{1,3})?$/';

    /**
     * @param  numeric-string  $value
     */
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::PATTERN, $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid quantity [{$value}]; expected a decimal with at most 3 places.");
        }

        return new self(self::normalise(bcadd($value, '0', self::SCALE)));
    }

    public static function fromInt(int $value): self
    {
        return self::fromString((string) $value);
    }

    public static function zero(): self
    {
        return new self('0.000');
    }

    public function add(self $other): self
    {
        return new self(self::normalise(bcadd($this->value, $other->value, self::SCALE)));
    }

    public function subtract(self $other): self
    {
        return new self(self::normalise(bcsub($this->value, $other->value, self::SCALE)));
    }

    public function negate(): self
    {
        return new self(self::normalise(bcmul($this->value, '-1', self::SCALE)));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === -1;
    }

    /**
     * @return numeric-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function normalise(string $value): string
    {
        // bcmath can yield "-0.000"; store zero without a sign.
        return bccomp($value, '0', self::SCALE) === 0 ? '0.000' : $value;
    }
}
