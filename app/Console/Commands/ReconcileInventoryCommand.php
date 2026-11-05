<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryLedger;
use Illuminate\Console\Command;

/**
 * Checks that every stock balance equals the sum of its movements
 * (BR-011, prompt invariant 12). Exits non-zero when anything differs, so it
 * can run from the scheduler or a deployment check.
 */
class ReconcileInventoryCommand extends Command
{
    protected $signature = 'brevon:inventory:reconcile {--location= : Limit to one location id}';

    protected $description = 'Verify stock balances against the movement ledger';

    public function handle(InventoryLedger $ledger): int
    {
        $location = $this->option('location');
        $mismatches = $ledger->reconcile(is_numeric($location) ? (int) $location : null);

        if ($mismatches->isEmpty()) {
            $this->info('All stock balances match the movement ledger.');

            return self::SUCCESS;
        }

        $this->error("{$mismatches->count()} balance(s) do not match the movement ledger.");
        $this->table(['Location', 'Item', 'Balance', 'Sum of movements'], $mismatches->map(fn (array $m) => array_values($m))->all());

        return self::FAILURE;
    }
}
