<?php

namespace Tests\Unit\Domain\Printing;

use App\Domain\Printing\EscPosReceipt;
use App\Domain\Printing\ReceiptLogo;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** D-195: the store logo as 32 mm of dithered printer dots, printed centred at the top. */
class ReceiptLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    /** A 200 × 100 PNG: left half black, right half cream, with a transparent strip at the bottom. */
    private function storeLogo(): string
    {
        $im = imagecreatetruecolor(200, 100);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, 99, 99, (int) imagecolorallocate($im, 10, 10, 10));
        imagefilledrectangle($im, 100, 0, 199, 99, (int) imagecolorallocate($im, 250, 245, 230));
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 90, 199, 99, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        Storage::disk('public')->put('branding/logo.png', (string) ob_get_clean());

        return 'branding/logo.png';
    }

    private function dot(string $bitmap, int $x, int $y): bool
    {
        $byte = ord($bitmap[2 + $y * 32 + intdiv($x, 8)]);

        return ($byte & (0x80 >> ($x % 8))) !== 0;
    }

    public function test_logo_becomes_a_256_dot_wide_bitmap_made_once(): void
    {
        $path = $this->storeLogo();
        $bitmap = (new ReceiptLogo)->bitmap($path);

        $this->assertIsString($bitmap);
        $height = unpack('n', substr($bitmap, 0, 2))[1];
        $this->assertSame(128, $height, '200 × 100 scaled to 256 wide');
        $this->assertSame(2 + 128 * 32, strlen($bitmap));
        $this->assertTrue($this->dot($bitmap, 20, 20), 'black part prints');
        $this->assertFalse($this->dot($bitmap, 220, 20), 'cream background prints white');
        $this->assertFalse($this->dot($bitmap, 20, 125), 'transparent part prints white');

        Storage::disk('public')->delete($path);
        $this->assertSame($bitmap, (new ReceiptLogo)->bitmap($path), 'kept after the first conversion');

        (new ReceiptLogo)->forget($path);
        $this->assertNull((new ReceiptLogo)->bitmap($path));
    }

    public function test_no_logo_or_an_unreadable_one_prints_without_it(): void
    {
        Storage::disk('public')->put('branding/broken.png', 'not an image');

        $this->assertNull((new ReceiptLogo)->bitmap(null));
        $this->assertNull((new ReceiptLogo)->bitmap('branding/missing.png'));
        $this->assertNull((new ReceiptLogo)->bitmap('branding/broken.png'));
    }

    public function test_receipt_starts_with_the_logo_centred_in_bands(): void
    {
        $bitmap = (string) (new ReceiptLogo)->bitmap($this->storeLogo());
        $bytes = (new EscPosReceipt)->testPage('Brevon', 58, $bitmap);

        // 128 rows in bands of 24: five of 24 and one of 8, each 48 bytes (384 dots) a row.
        $this->assertSame(6, substr_count($bytes, "\x1Dv0\x00\x30\x00"));
        $this->assertStringContainsString("\x1Dv0\x00\x30\x00\x18\x00", $bytes);
        $this->assertStringContainsString("\x1Dv0\x00\x30\x00\x08\x00", $bytes);
        $first = strpos($bytes, "\x1Dv0") + 8;
        $this->assertSame(str_repeat("\x00", 8), substr($bytes, $first, 8), '64 white dots on the left');
        $this->assertSame(substr($bitmap, 2, 32), substr($bytes, $first + 8, 32));
        $this->assertSame(str_repeat("\x00", 8), substr($bytes, $first + 40, 8), '64 white dots on the right');
        $this->assertLessThan(strpos($bytes, 'Printer test'), $first, 'logo before the text');

        $wide = (new EscPosReceipt)->testPage('Brevon', 80, $bitmap);
        $this->assertStringContainsString("\x1Dv0\x00\x48\x00\x18\x00", $wide, '576 dots on 80 mm');
        $this->assertStringNotContainsString("\x1Dv0", (new EscPosReceipt)->testPage('Brevon'));
    }
}
