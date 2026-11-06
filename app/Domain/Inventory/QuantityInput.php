<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity;
use InvalidArgumentException;

/** Reads a quantity typed by staff, respecting the unit's decimal places. */
final class QuantityInput
{
    /**
     * @param  bool  $signed  allow a leading minus (adjustments that remove stock)
     */
    public static function parse(string $raw, int $precision, bool $allowZero = false, bool $signed = false): ?Quantity
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match($signed ? '/^-?\d{1,10}(\.\d+)?$/' : '/^\d{1,10}(\.\d+)?$/', $raw) !== 1) {
            return null;
        }

        $fraction = str_contains($raw, '.') ? rtrim(substr($raw, strpos($raw, '.') + 1), '0') : '';
        if (strlen($fraction) > $precision) {
            return null;
        }

        try {
            $quantity = Quantity::fromString($raw);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($quantity->isZero()) {
            return $allowZero ? $quantity : null;
        }

        return $signed || $quantity->isPositive() ? $quantity : null;
    }

    public static function message(int $precision, bool $allowZero = false): string
    {
        return match (true) {
            $precision === 0 && $allowZero => __('Enter a whole number, 0 or more.'),
            $precision === 0 => __('Enter a whole number greater than zero.'),
            $allowZero => __('Enter a quantity of 0 or more, with up to :places decimal places.', ['places' => $precision]),
            default => __('Enter a quantity greater than zero, with up to :places decimal places.', ['places' => $precision]),
        };
    }
}
