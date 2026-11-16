<?php

namespace App\Domain\Printing;

use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The store logo as thermal-printer dots (D-195): 32 mm wide (256 of the
 * XP-58IID's 384 dots), black and white with Floyd–Steinberg dithering so
 * light and coloured parts (Brevon's gold rings and leaves) print as dot
 * shading. Transparent parts count as white, and the colours are stretched
 * so a cream background prints white.
 *
 * Converted once per uploaded logo and kept on the private `local` disk
 * (printing/logo-<hash>.bin), so receipts never redo the work. The store
 * settings warm it on upload and delete it with the old logo. If the logo
 * cannot be read (or GD is missing), receipts print without it — a print
 * must never fail because of the logo.
 */
class ReceiptLogo
{
    /** Logo width in dots (32 mm at 8 dots/mm). A multiple of 8. */
    public const WIDTH = 256;

    /** Tallest logo allowed, in dots, so a tall image cannot waste paper. */
    public const MAX_HEIGHT = 256;

    /** Packed bitmap: 2-byte height (big-endian), then WIDTH/8 bytes a row, top bit = leftmost dot, 1 = black. */
    public function bitmap(?string $logoPath): ?string
    {
        if ($logoPath === null || $logoPath === '') {
            return null;
        }
        $cache = $this->cachePath($logoPath);
        if (Storage::disk('local')->exists($cache)) {
            return Storage::disk('local')->get($cache);
        }

        $bitmap = $this->convert($logoPath);
        if ($bitmap !== null) {
            Storage::disk('local')->put($cache, $bitmap);
        }

        return $bitmap;
    }

    /** Convert a newly uploaded logo now, so the first receipt prints fast. */
    public function prepare(?string $logoPath): void
    {
        $this->bitmap($logoPath);
    }

    public function forget(?string $logoPath): void
    {
        if ($logoPath !== null && $logoPath !== '') {
            Storage::disk('local')->delete($this->cachePath($logoPath));
        }
    }

    private function cachePath(string $logoPath): string
    {
        return 'printing/logo-'.sha1($logoPath).'.bin';
    }

    private function convert(string $logoPath): ?string
    {
        try {
            $contents = Storage::disk('public')->get($logoPath);
            if (! function_exists('imagecreatefromstring') || $contents === null || $contents === '') {
                return null;
            }
            $source = @imagecreatefromstring($contents);
            if (! $source instanceof GdImage) {
                return null;
            }

            return $this->dither($this->grey($source));
        } catch (Throwable $e) {
            Log::warning('Receipt logo could not be converted for printing.', ['path' => $logoPath, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Scaled to WIDTH dots wide (height in proportion, at most MAX_HEIGHT, then
     * centred), on white, as 0 (black) – 255 (white) levels, contrast stretched.
     *
     * @return list<list<float>>
     */
    private function grey(GdImage $source): array
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = min(self::WIDTH / $sw, self::MAX_HEIGHT / $sh);
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));

        $canvas = imagecreatetruecolor(self::WIDTH, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, intdiv(self::WIDTH - $w, 2), 0, 0, 0, $w, $h, $sw, $sh);

        $levels = [];
        $all = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < self::WIDTH; $x++) {
                $c = imagecolorat($canvas, $x, $y);
                $l = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
                $row[] = $l;
                $all[] = $l;
            }
            $levels[] = $row;
        }

        // Stretch so the darkest ink prints solid and a light (cream) background prints white.
        sort($all);
        $n = count($all);
        $low = $all[(int) floor(($n - 1) * 0.01)];
        $high = max($low + 1, min(235.0, $all[(int) floor(($n - 1) * 0.95)]));
        foreach ($levels as $y => $row) {
            foreach ($row as $x => $l) {
                $levels[$y][$x] = max(0.0, min(255.0, ($l - $low) * 255 / ($high - $low)));
            }
        }

        return $levels;
    }

    /** @param  list<list<float>>  $levels */
    private function dither(array $levels): string
    {
        $h = count($levels);
        $out = pack('n', $h);
        for ($y = 0; $y < $h; $y++) {
            $bytes = array_fill(0, intdiv(self::WIDTH, 8), 0);
            for ($x = 0; $x < self::WIDTH; $x++) {
                $old = $levels[$y][$x];
                $new = $old < 128 ? 0.0 : 255.0;
                if ($new === 0.0) {
                    $bytes[intdiv($x, 8)] |= 0x80 >> ($x % 8);
                }
                $err = $old - $new;
                if ($x + 1 < self::WIDTH) {
                    $levels[$y][$x + 1] += $err * 7 / 16;
                }
                if ($y + 1 < $h) {
                    if ($x > 0) {
                        $levels[$y + 1][$x - 1] += $err * 3 / 16;
                    }
                    $levels[$y + 1][$x] += $err * 5 / 16;
                    if ($x + 1 < self::WIDTH) {
                        $levels[$y + 1][$x + 1] += $err / 16;
                    }
                }
            }
            $out .= pack('C*', ...$bytes);
        }

        return $out;
    }
}
