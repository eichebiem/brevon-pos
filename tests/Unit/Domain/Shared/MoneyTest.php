<?php

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** T-MNY: money arithmetic without floats (NFR-007, D-034). */
class MoneyTest extends TestCase
{
    public function test_it_parses_decimal_strings_into_centavos(): void
    {
        $this->assertSame(12550, Money::fromDecimal('125.50')->minor());
        $this->assertSame(12550, Money::fromDecimal('125.5')->minor());
        $this->assertSame(12500, Money::fromDecimal('125')->minor());
        $this->assertSame(5, Money::fromDecimal('0.05')->minor());
        $this->assertSame(-2000, Money::fromDecimal('-20.00')->minor());
        $this->assertSame(-5, Money::fromDecimal('-0.05')->minor());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'three decimals' => ['1.005'],
            'comma' => ['1,000.00'],
            'empty' => [''],
            'letters' => ['abc'],
            'scientific' => ['1e3'],
            'currency symbol' => ['₱10'],
            'trailing dot' => ['10.'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_malformed_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal($amount);
    }

    public function test_addition_and_subtraction_are_exact(): void
    {
        // 0.1 + 0.2 is the classic float failure.
        $sum = Money::fromDecimal('0.10')->add(Money::fromDecimal('0.20'));

        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertSame('-0.10', Money::fromDecimal('0.20')->subtract(Money::fromDecimal('0.30'))->toDecimal());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function multiplications(): array
    {
        return [
            'whole quantity' => ['45.00', '3', '135.00'],
            'half rounds up' => ['12.35', '0.5', '6.18'],
            'below half rounds down' => ['10.01', '0.333', '3.33'],
            'three-decimal quantity' => ['99.99', '1.250', '124.99'],
            'negative half rounds away from zero' => ['-12.35', '0.5', '-6.18'],
            'zero quantity' => ['50.00', '0', '0.00'],
        ];
    }

    #[DataProvider('multiplications')]
    public function test_multiply_by_quantity_rounds_half_up_to_the_centavo(string $price, string $quantity, string $expected): void
    {
        $this->assertSame($expected, Money::fromDecimal($price)->multiply($quantity)->toDecimal());
        $this->assertSame($expected, Money::fromDecimal($price)->multiply(Quantity::fromString($quantity))->toDecimal());
    }

    public function test_sum_and_comparisons(): void
    {
        $total = Money::sum([Money::fromDecimal('45.00'), Money::fromDecimal('120.50'), Money::fromDecimal('0.50')]);

        $this->assertSame('166.00', $total->toDecimal());
        $this->assertTrue($total->greaterThan(Money::fromDecimal('165.99')));
        $this->assertTrue($total->greaterThanOrEqual(Money::fromDecimal('166')));
        $this->assertTrue(Money::fromDecimal('1')->lessThan($total));
        $this->assertTrue($total->equals(Money::fromMinor(16600)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::fromDecimal('-1')->isNegative());
        $this->assertTrue($total->isPositive());
    }

    public function test_cash_change_is_tendered_minus_due(): void
    {
        // BR-006: change = tendered - due, and is not revenue.
        $due = Money::fromDecimal('166.00');
        $tendered = Money::fromDecimal('200.00');

        $this->assertSame('34.00', $tendered->subtract($due)->toDecimal());
    }

    public function test_formatting_for_display_and_storage(): void
    {
        $this->assertSame('₱1,234.50', Money::fromDecimal('1234.5')->format());
        $this->assertSame('₱0.05', Money::fromDecimal('0.05')->format());
        $this->assertSame('-₱20.00', Money::fromDecimal('-20')->format());
        $this->assertSame('1234567.89', Money::fromDecimal('1234567.89')->toDecimal());
        $this->assertSame('"12.30"', json_encode(Money::fromDecimal('12.3')));
        $this->assertSame('12.30', (string) Money::fromDecimal('12.3'));
    }

    public function test_currencies_cannot_be_mixed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1.00', 'PHP')->add(Money::fromDecimal('1.00', 'USD'));
    }

    public function test_invalid_currency_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::zero('PESO');
    }
}
