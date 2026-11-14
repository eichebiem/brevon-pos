<?php

namespace App\Domain\System;

use Carbon\CarbonImmutable;

/**
 * Reads the newest Laravel log entries for the System logs page (FR-102,
 * D-184). Only the end of the newest log file is read (at most 1 MB), and
 * every secret the app knows about is masked before anything leaves the
 * server: the app key, database, mail and other passwords, tokens and
 * `password=`-style values.
 */
final class LogReader
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    private const MAX_BYTES = 1_048_576;

    private const MASK = '[hidden]';

    public function __construct(private readonly ?string $directory = null) {}

    /** The newest laravel*.log file, or null when there is none. */
    public function file(): ?string
    {
        $files = glob(($this->directory ?? storage_path('logs')).'/laravel*.log') ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /**
     * Newest first.
     *
     * @return list<array{at: string|null, level: string, message: string, details: string}>
     */
    public function entries(?string $level = null, ?string $search = null, int $limit = 200): array
    {
        $file = $this->file();
        if ($file === null || ! is_readable($file)) {
            return [];
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, $size - self::MAX_BYTES));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        // Each entry starts with "[YYYY-MM-DD HH:MM:SS] env.LEVEL: ".
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $text) ?: [];
        $entries = [];
        foreach ($parts as $part) {
            if (! preg_match('/^\[(?<at>[^\]]+)\] \w+\.(?<level>[A-Z]+): (?<rest>.*)$/s', $part, $m)) {
                continue; // the cut-off first fragment
            }
            $entryLevel = strtolower($m['level']);
            if ($level !== null && $entryLevel !== $level) {
                continue;
            }
            $rest = $this->mask(rtrim($m['rest']));
            if ($search !== null && $search !== '' && stripos($rest, $search) === false) {
                continue;
            }
            [$message, $details] = array_pad(explode("\n", $rest, 2), 2, '');
            $entries[] = [
                'at' => $this->timestamp($m['at']),
                'level' => $entryLevel,
                'message' => mb_strimwidth($message, 0, 500, '…'),
                'details' => mb_strimwidth($details, 0, 20000, "\n…"),
            ];
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }

    public function mask(string $text): string
    {
        $appKey = (string) config('app.key');
        $secrets = array_filter([
            $appKey,
            str_starts_with($appKey, 'base64:') ? substr($appKey, 7) : null,
            ...(array) config('app.previous_keys'),
            ...array_column((array) config('database.connections'), 'password'),
            config('mail.mailers.smtp.password'),
            config('services.postmark.key'),
            config('services.resend.key'),
            config('services.ses.secret'),
        ], fn (mixed $s) => is_string($s) && strlen($s) >= 4);
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $text = str_replace($secrets, self::MASK, $text);

        $text = (string) preg_replace('/base64:[A-Za-z0-9+\/=]{16,}/', self::MASK, $text);
        $text = (string) preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 '.self::MASK, $text);

        return (string) preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|token|api[_-]?key|approver_password)\b["\']?\s*(?:=>|[:=])\s*["\']?)([^\s"\',&}]+)/i',
            '$1'.self::MASK,
            $text,
        );
    }

    private function timestamp(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
