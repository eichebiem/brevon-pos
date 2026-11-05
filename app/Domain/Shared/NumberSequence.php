<?php

namespace App\Domain\Shared;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Concurrency-safe document counters per location, type and Manila business
 * day (D-035, BR-016). Must be called inside the transaction that uses the
 * number: the counter row stays locked until that transaction ends, and a
 * rollback releases the number (gaps happen only on rollback).
 */
class NumberSequence
{
    public function next(Branch $branch, string $type, CarbonImmutable $businessDate): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be taken inside a database transaction.');
        }

        $key = ['branch_id' => $branch->id, 'sequence_type' => $type, 'business_date' => $businessDate->toDateString()];
        $now = now();

        // Lock an existing counter directly. Inserting first (INSERT IGNORE)
        // would take a shared lock on the existing row, and two requests then
        // deadlock when both upgrade it (same finding as D-096).
        $row = DB::table('number_sequences')->where($key)->lockForUpdate()->first(['id', 'last_number']);
        if ($row === null) {
            DB::table('number_sequences')->insertOrIgnore([...$key, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $row = DB::table('number_sequences')->where($key)->lockForUpdate()->first(['id', 'last_number']);
        }

        if ($row === null) {
            throw new LogicException('Number sequence row missing after insert.');
        }

        $next = (int) $row->last_number + 1;
        DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => $now]);

        return $next;
    }
}
