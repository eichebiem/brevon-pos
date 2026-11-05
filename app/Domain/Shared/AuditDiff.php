<?php

namespace App\Domain\Shared;

/**
 * Before/after pairs for audit records: only keys whose value changed.
 */
final class AuditDiff
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function between(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if ($from !== $to) {
                $old[$key] = $from;
                $new[$key] = $to;
            }
        }

        return [$old, $new];
    }
}
