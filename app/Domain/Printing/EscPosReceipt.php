<?php

namespace App\Domain\Printing;

use App\Domain\Sales\OrderReceipt;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The order receipt as raw ESC/POS bytes for a thermal printer reached
 * without the browser's print dialog (D-194): the counter's Xprinter
 * XP-58IID (58 mm, 32 characters a line in font A) through the Android app.
 * 80 mm (48 characters) is available for other printers.
 *
 * It prints exactly what the browser receipt (ReceiptView.vue) prints, from
 * the same OrderReceipt data, so a direct print and a browser print of one
 * order match. It never creates or changes anything; reprints are still
 * audited by the reprint action. The store logo, when there is one, prints
 * first, centred, as a dithered raster image from ReceiptLogo (D-195).
 *
 * Text is sent as plain ASCII (code page PC437) with the printer's Chinese
 * mode switched off. The peso sign is drawn by us as a user-defined
 * character (ESC &) in place of the backtick, so it prints the same on any
 * ESC/POS code page; other non-ASCII letters are transliterated (ñ → n).
 */
class EscPosReceipt
{
    public const WIDTHS = [58 => 32, 80 => 48];

    /** Printable dots a line for each paper width (8 dots/mm). */
    public const DOTS = [58 => 384, 80 => 576];

    /** Rows sent per raster command, so a slow Bluetooth link never overruns the printer's buffer. */
    private const BAND_ROWS = 24;

    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** The byte the peso glyph replaces while user-defined characters are on. */
    private const PESO_BYTE = '`';

    /** 12 × 24 dot peso sign for font A ('#' = printed dot). */
    private const PESO_GLYPH = [
        '............', '............', '............',
        '.#######....', '.#########..', '.##.....###.',
        '############', '############', '.##......##.',
        '############', '############', '.##.....###.',
        '.#########..', '.#######....', '.##.........',
        '.##.........', '.##.........', '.##.........',
        '.##.........', '.##.........', '.##.........',
        '............', '............', '............',
    ];

    private int $columns = 32;

    private string $out = '';

    /**
     * @param  array<string, mixed>  $receipt  OrderReceipt::for() output
     * @param  string|null  $logo  ReceiptLogo::bitmap() output, or null for no logo
     */
    public function render(array $receipt, int $paperMm = 58, ?string $logo = null): string
    {
        $this->start($paperMm);
        $order = $receipt['order'];

        $this->logo($logo, $paperMm);
        $this->align('center');
        $this->style(bold: true, tall: true);
        $this->wrapped((string) $receipt['store']['name']);
        $this->style();
        $this->wrapped((string) $receipt['location']['name']);
        if (! empty($receipt['location']['address'])) {
            $this->wrapped((string) $receipt['location']['address']);
        }
        $this->rule();
        $this->style(bold: true);
        $this->wrapped((string) ($receipt['title'] ?? OrderReceipt::TITLE));
        $this->style();
        $this->rule();
        $banner = match ($order['status']) {
            'cancelled' => 'CANCELLED - REFUNDED',
            'refunded' => 'REFUNDED IN FULL',
            default => null,
        };
        if ($banner !== null) {
            $this->style(bold: true, tall: true);
            $this->wrapped($banner);
            $this->style();
        }
        $this->align('left');

        $this->pair('Order', (string) $order['order_number'], boldRight: true);
        $this->pair('Date', $this->dateTime((string) $order['created_at']));
        $this->pair('Type', (string) $order['order_type_label']);
        $cashier = trim(($order['cashier'] ?? '').($order['terminal'] ? ' - '.$order['terminal'] : ''));
        $this->pair('Cashier', $cashier === '' ? '-' : $cashier);
        $this->rule();

        foreach ($receipt['items'] as $item) {
            $name = $this->quantity((string) $item['quantity']).' x '.$item['name'].($item['variant'] ? ' ('.$item['variant'].')' : '');
            $this->pair($name, $this->money($item['line_total']));
            if ((float) $item['quantity'] !== 1.0) {
                $this->line('  @ '.$this->money($item['unit_price']));
            }
            if (! empty($item['notes'])) {
                $this->wrapped('"'.$item['notes'].'"', indent: 2);
            }
        }
        $this->rule();

        $this->pair('Subtotal', $this->money($order['subtotal']));
        $discount = $receipt['discount'] ?? null;
        if ((float) $order['discount_total'] > 0) {
            $label = ($discount['name'] ?? 'Discount')
                .(($discount['type'] ?? null) === 'percentage' ? ' ('.$this->quantity((string) $discount['value']).'%)' : '');
            $this->pair($label, '-'.$this->money($order['discount_total']));
        }
        $this->style(bold: true, tall: true);
        $this->pair('TOTAL', $this->money($order['grand_total']));
        $this->style();

        if (! empty($discount['beneficiary'])) {
            $b = $discount['beneficiary'];
            $this->wrapped($b['type_label'].': '.$b['name'].' - ID '.$b['card_number']);
        }
        if (! empty($discount['approved_by'])) {
            $this->wrapped('Discount approved by '.$discount['approved_by']);
        }

        foreach ($receipt['payments'] as $payment) {
            $this->pair((string) ($payment['method'] ?? 'Payment'), $this->money($payment['amount']));
            if ($payment['amount_tendered'] !== null) {
                $this->pair('Cash received', $this->money($payment['amount_tendered']));
                $this->pair('Change', $this->money($payment['change_amount']));
            }
            if (! empty($payment['reference_number'])) {
                $this->pair('Reference', (string) $payment['reference_number']);
            }
            if ($payment['status'] !== 'verified') {
                $this->pair('Status', $this->paymentStatus((string) $payment['status']));
            }
        }

        if ($receipt['refunds'] !== []) {
            $this->rule();
            foreach ($receipt['refunds'] as $refund) {
                $this->pair('Refund '.$refund['refund_number'], '-'.$this->money($refund['amount']));
            }
        }

        if (! empty($order['notes'])) {
            $this->rule();
            $this->wrapped('Note: '.$order['notes']);
        }

        $this->rule();
        $this->align('center');
        if (! empty($receipt['store']['footer'])) {
            $this->wrapped((string) $receipt['store']['footer']);
        }
        $this->wrapped('This is not an official receipt.');

        return $this->finish();
    }

    /** A short page to check the printer from the app's settings screen. */
    public function testPage(string $storeName, int $paperMm = 58, ?string $logo = null): string
    {
        $this->start($paperMm);
        $this->logo($logo, $paperMm);
        $this->align('center');
        $this->style(bold: true, tall: true);
        $this->wrapped($storeName);
        $this->style();
        $this->wrapped('Printer test');
        $this->rule();
        $this->align('left');
        $this->pair('Paper', $paperMm.' mm, '.$this->columns.' characters');
        $this->pair('Peso sign', Money::fromDecimal('1234.50')->format());
        $this->line(str_repeat('1234567890', 10));
        $this->style(bold: true);
        $this->line('Bold text');
        $this->style(tall: true);
        $this->line('Tall text');
        $this->style();
        $this->rule();
        $this->align('center');
        $this->wrapped('If every line above fits and the peso sign shows, the printer is ready.');

        return $this->finish();
    }

    private function start(int $paperMm): void
    {
        if (! isset(self::WIDTHS[$paperMm])) {
            throw new InvalidArgumentException("Unsupported paper width [{$paperMm}].");
        }
        $this->columns = self::WIDTHS[$paperMm];
        $this->out = self::ESC.'@'      // initialise
            ."\x1C."                    // FS . — Chinese (Kanji) mode off
            .self::ESC."t\x00"          // code page PC437
            .self::ESC.'M'."\x00"       // font A (12 × 24)
            .$this->pesoDefinition()
            .self::ESC.'%'."\x01";      // use user-defined characters
    }

    /**
     * GS v 0 raster bands. The bitmap is padded with white on both sides to the
     * full paper width, so it is centred whatever the printer does with ESC a.
     */
    private function logo(?string $bitmap, int $paperMm): void
    {
        if ($bitmap === null || strlen($bitmap) < 2) {
            return;
        }
        $height = (ord($bitmap[0]) << 8) | ord($bitmap[1]);
        $rowBytes = intdiv(ReceiptLogo::WIDTH, 8);
        if ($height < 1 || strlen($bitmap) !== 2 + $height * $rowBytes) {
            return;
        }
        $lineBytes = intdiv(self::DOTS[$paperMm], 8);
        $left = str_repeat("\x00", intdiv($lineBytes - $rowBytes, 2));
        $right = str_repeat("\x00", $lineBytes - $rowBytes - strlen($left));

        $this->align('left');
        for ($top = 0; $top < $height; $top += self::BAND_ROWS) {
            $rows = min(self::BAND_ROWS, $height - $top);
            $this->out .= self::GS.'v0'."\x00".pack('v', $lineBytes).pack('v', $rows);
            for ($y = $top; $y < $top + $rows; $y++) {
                $this->out .= $left.substr($bitmap, 2 + $y * $rowBytes, $rowBytes).$right;
            }
        }
        $this->out .= self::ESC.'d'."\x01";
    }

    private function finish(): string
    {
        $this->out .= self::ESC.'d'."\x04"; // feed 4 lines so the receipt clears the tear bar

        return $this->out;
    }

    /** ESC & 3 c c 12 [36 bytes]: the peso sign, column by column, top bit first. */
    private function pesoDefinition(): string
    {
        $bytes = '';
        for ($x = 0; $x < 12; $x++) {
            for ($k = 0; $k < 3; $k++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    if (self::PESO_GLYPH[$k * 8 + $bit][$x] === '#') {
                        $byte |= 0x80 >> $bit;
                    }
                }
                $bytes .= chr($byte);
            }
        }

        return self::ESC.'&'."\x03".self::PESO_BYTE.self::PESO_BYTE."\x0C".$bytes;
    }

    private function align(string $where): void
    {
        $this->out .= self::ESC.'a'.chr(['left' => 0, 'center' => 1][$where]);
    }

    private function style(bool $bold = false, bool $tall = false): void
    {
        $this->out .= self::ESC.'E'.chr($bold ? 1 : 0).self::GS.'!'.chr($tall ? 0x01 : 0x00);
    }

    private function rule(): void
    {
        $this->line(str_repeat('-', $this->columns));
    }

    private function line(string $text): void
    {
        $this->out .= $this->encode(mb_substr($text, 0, $this->columns))."\n";
    }

    private function wrapped(string $text, int $indent = 0): void
    {
        foreach ($this->wrap($this->clean($text), $this->columns - $indent) as $part) {
            $this->line(str_repeat(' ', $indent).$part);
        }
    }

    /** Label on the left, value on the right; a long label wraps above the value line. */
    private function pair(string $label, string $value, bool $boldRight = false): void
    {
        $label = $this->clean($label);
        $value = $this->clean($value);
        $room = max(1, $this->columns - mb_strlen($value) - 1);
        $parts = $this->wrap($label, $room);
        $last = (string) array_pop($parts);
        foreach ($parts as $part) {
            $this->line($part);
        }
        $gap = str_repeat(' ', max(1, $this->columns - mb_strlen($last) - mb_strlen($value)));
        if ($boldRight) {
            $this->out .= $this->encode($last.$gap).self::ESC.'E'."\x01".$this->encode($value).self::ESC.'E'."\x00"."\n";

            return;
        }
        $this->line($last.$gap.$value);
    }

    /** @return list<string> */
    private function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
            $current = '';
            foreach (preg_split('/ +/u', trim($paragraph)) ?: [] as $word) {
                while (mb_strlen($word) > $width) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, $width);
                    $word = mb_substr($word, $width);
                }
                if ($current === '') {
                    $current = $word;
                } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                    $current .= ' '.$word;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }
            $lines[] = $current;
        }

        return $lines;
    }

    /** Printable text: ASCII plus the peso sign, one character a column. */
    private function clean(string $text): string
    {
        $text = strtr($text, [
            '—' => '-', '–' => '-', '−' => '-', '×' => 'x', '·' => '-', '…' => '...',
            '“' => '"', '”' => '"', '‘' => "'", '’' => "'", self::PESO_BYTE => "'", "\t" => ' ',
        ]);
        $parts = array_map(
            fn (string $part) => (string) preg_replace('/[^\x20-\x7E\n]/', '?', Str::ascii($part)),
            explode('₱', $text),
        );

        return implode('₱', $parts);
    }

    private function encode(string $text): string
    {
        return str_replace('₱', self::PESO_BYTE, $this->clean($text));
    }

    private function money(mixed $value): string
    {
        return Money::fromDecimal((string) $value)->format();
    }

    /** Same as formatQuantity() in resources/js/lib/format.ts. */
    private function quantity(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return number_format((int) $whole).($fraction === '' ? '' : '.'.$fraction);
    }

    private function dateTime(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone(BusinessClock::timezone())->format('M j, Y, g:i A');
    }

    private function paymentStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'Waiting for verification',
            'failed' => 'Not received',
            'refunded' => 'Refunded',
            'partially_refunded' => 'Partly refunded',
            default => $status,
        };
    }
}
