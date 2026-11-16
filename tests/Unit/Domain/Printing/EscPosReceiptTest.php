<?php

namespace Tests\Unit\Domain\Printing;

use App\Domain\Printing\EscPosReceipt;
use App\Domain\Sales\OrderReceipt;
use InvalidArgumentException;
use Tests\TestCase;

/** D-194: the order receipt as ESC/POS bytes for the 58 mm Xprinter XP-58IID. */
class EscPosReceiptTest extends TestCase
{
    /** @return array<string, mixed> */
    private function receipt(array $order = [], array $extra = []): array
    {
        return array_replace([
            'title' => OrderReceipt::TITLE,
            'store' => ['name' => 'Brevon Doughnuts & Coffee', 'logo_url' => null, 'footer' => 'Salamat po!'],
            'location' => ['code' => 'MAIN', 'name' => 'Main Branch', 'address' => 'Purok 1, Bañadero, Calamba City, Laguna'],
            'order' => array_replace([
                'id' => 1, 'order_number' => 'MAIN-20261116-0001', 'order_type' => 'takeout', 'order_type_label' => 'Takeout',
                'status' => 'completed', 'payment_status' => 'paid', 'created_at' => '2026-11-16T07:04:00+00:00',
                'notes' => null, 'cashier' => 'Cashier One', 'terminal' => 'POS-1',
                'subtotal' => '1332.00', 'discount_total' => '0.00', 'grand_total' => '1332.00',
            ], $order),
            'items' => [
                ['name' => 'Choco Custard', 'variant' => null, 'quantity' => '2.000', 'unit_price' => '44.00', 'line_total' => '88.00', 'discount_amount' => '0.00', 'notes' => null],
                ['name' => 'Assorted Premium Box of Twelve Doughnuts', 'variant' => 'Box of 12', 'quantity' => '1.000', 'unit_price' => '1244.00', 'line_total' => '1244.00', 'discount_amount' => '0.00', 'notes' => 'no nuts “please”'],
            ],
            'discount' => null,
            'refunds' => [],
            'payments' => [
                ['method' => 'Cash', 'amount' => '1332.00', 'amount_tendered' => '1500.00', 'change_amount' => '168.00', 'reference_number' => null, 'status' => 'verified'],
            ],
        ], $extra);
    }

    /** Printed text lines, with the ESC/POS commands taken out. */
    private function lines(string $bytes): array
    {
        $text = preg_replace([
            '/\x1B&\x03``\x0C.{36}/s', '/\x1B@/', '/\x1C\./', '/\x1B[tMa%EdE]./s', '/\x1D!./s',
        ], '', $bytes);

        return array_values(array_filter(explode("\n", (string) $text), fn ($l) => $l !== ''));
    }

    public function test_58_mm_receipt_fits_32_columns_and_prints_the_sale(): void
    {
        $bytes = (new EscPosReceipt)->render($this->receipt());
        $lines = $this->lines($bytes);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(32, strlen($line), $line);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $line, 'plain ASCII only');
        }
        $text = implode("\n", $lines);
        $this->assertStringContainsString('Order Receipt - not an official', $text);
        $this->assertStringContainsString('Banadero', $text, 'ñ is transliterated');
        $this->assertContains('Order'.str_repeat(' ', 9).'MAIN-20261116-0001', $lines, 'right-aligned');
        $this->assertStringContainsString('Nov 16, 2026, 3:04 PM', $text, 'Manila time');
        $this->assertContains('Cashier'.str_repeat(' ', 6).'Cashier One - POS-1', $lines);
        $this->assertStringContainsString(str_pad('2 x Choco Custard', 26).'`88.00', $text);
        $this->assertStringContainsString('  @ `44.00', $text);
        $this->assertStringContainsString('"no nuts "please""', $text);
        $this->assertStringContainsString('(Box of 12)', $text);
        $this->assertStringContainsString(str_pad('TOTAL', 23).'`1,332.00', $text);
        $this->assertStringContainsString(str_pad('Change', 25).'`168.00', $text);
        $this->assertStringContainsString('This is not an official receipt.', $text);
        $this->assertStringContainsString('Salamat po!', $text);
    }

    public function test_it_sets_up_the_printer_and_draws_the_peso_sign(): void
    {
        $bytes = (new EscPosReceipt)->render($this->receipt());

        $this->assertStringStartsWith("\x1B@\x1C.\x1Bt\x00\x1BM\x00\x1B&\x03``\x0C", $bytes, 'reset, Chinese mode off, PC437, font A, peso glyph');
        $this->assertStringContainsString("\x1B%\x01", $bytes, 'user-defined characters on');
        $this->assertStringNotContainsString('₱', $bytes, 'never sent as UTF-8');
        $this->assertStringEndsWith("\x1Bd\x04", $bytes);
    }

    public function test_80_mm_uses_48_columns_and_other_widths_are_refused(): void
    {
        $lines = $this->lines((new EscPosReceipt)->render($this->receipt(), 80));
        $this->assertContains(str_repeat('-', 48), $lines);
        $this->assertStringContainsString(str_pad('TOTAL', 39).'`1,332.00', implode("\n", $lines));

        $this->expectException(InvalidArgumentException::class);
        (new EscPosReceipt)->render($this->receipt(), 76);
    }

    public function test_discount_refunds_cancellation_and_gcash_are_printed(): void
    {
        $text = implode("\n", $this->lines((new EscPosReceipt)->render($this->receipt(
            ['status' => 'cancelled', 'discount_total' => '17.60', 'grand_total' => '70.40', 'notes' => 'Pick up at 5'],
            [
                'discount' => ['name' => 'Senior Citizen', 'code' => 'SC', 'type' => 'percentage', 'value' => '20.00', 'amount' => '17.60', 'approved_by' => 'Harris',
                    'beneficiary' => ['type_label' => 'Senior citizen', 'name' => 'J*** D**', 'card_number' => '****1234']],
                'refunds' => [['refund_number' => 'RF-0001', 'amount' => '70.40', 'at' => '2026-11-16T08:00:00+00:00']],
                'payments' => [['method' => 'GCash', 'amount' => '70.40', 'amount_tendered' => null, 'change_amount' => null, 'reference_number' => '1234567890123', 'status' => 'refunded']],
            ],
        ))));

        $this->assertStringContainsString('CANCELLED - REFUNDED', $text);
        $this->assertStringContainsString('Senior Citizen (20%)', $text);
        $this->assertStringContainsString('-`17.60', $text);
        $this->assertStringContainsString("Senior citizen: J*** D** - ID\n****1234", $text, 'wrapped at 32');
        $this->assertStringContainsString('Discount approved by Harris', $text);
        $this->assertStringContainsString('Reference', $text);
        $this->assertStringContainsString('1234567890123', $text);
        $this->assertStringContainsString('Status', $text);
        $this->assertStringContainsString('Refunded', $text);
        $this->assertStringContainsString('Refund RF-0001', $text);
        $this->assertStringContainsString('Note: Pick up at 5', $text);
        $this->assertStringNotContainsString('Cash received', $text);
    }

    public function test_peso_glyph_is_a_12_by_24_dot_character(): void
    {
        $bytes = (new EscPosReceipt)->testPage('Brevon');
        $definition = substr($bytes, strpos($bytes, "\x1B&") + 6, 36);

        $this->assertSame(36, strlen($definition));
        $this->assertNotSame(str_repeat("\x00", 36), $definition);
        $this->assertStringContainsString('Peso sign', $bytes);
        $this->assertStringContainsString('`1,234.50', $bytes);
    }
}
