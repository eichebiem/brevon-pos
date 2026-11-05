<?php

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Quantities match DECIMAL(14,3) and never use floats (SRS §8.1). */
class QuantityTest extends TestCase
{
    public function test_it_normalises_to_three_decimals(): void
    {
        $this->assertSame('2.000', Quantity::fromString('2')->toString());
        $this->assertSame('0.250', Quantity::fromString('0.25')->toString());
        $this->assertSame('-1.500', Quantity::fromString('-1.5')->toString());
        $this->assertSame('0.000', Quantity::fromString('-0')->toString());
        $this->assertSame('12.000', Quantity::fromInt(12)->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'four decimals' => ['1.0005'],
            'letters' => ['two'],
            'empty' => [''],
            'scientific' => ['1e2'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_it_rejects_malformed_quantities(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Quantity::fromString($value);
    }

    public function test_arithmetic_and_comparison(): void
    {
        $onHand = Quantity::fromString('10.5');
        $sold = Quantity::fromString('0.75');

        $this->assertSame('9.750', $onHand->subtract($sold)->toString());
        $this->assertSame('11.250', $onHand->add($sold)->toString());
        $this->assertSame('-0.750', $sold->negate()->toString());
        $this->assertSame(1, $onHand->compare($sold));
        $this->assertSame(0, $sold->compare(Quantity::fromString('0.750')));
        $this->assertTrue($sold->subtract($sold)->isZero());
        $this->assertTrue($sold->isPositive());
        $this->assertTrue($sold->negate()->isNegative());
        $this->assertSame('"0.750"', json_encode($sold));
    }
}
