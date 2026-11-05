<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithStaff;
use Tests\Concerns\InteractsWithStock;
use Tests\TestCase;
use Throwable;

/**
 * T-INV-3: real concurrency. Data is committed (no wrapping transaction) and
 * two forked processes, each with its own MySQL connection, try to sell the
 * last unit at the same moment. Row locking must let exactly one succeed.
 */
#[Group('concurrency')]
class InventoryConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithStaff;
    use InteractsWithStock;

    protected function tearDown(): void
    {
        // Committed rows would leak into later tests; empty the tables again.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_processes_selling_the_last_unit_only_one_succeeds(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }

        $this->seedAccess();
        $actor = $this->staff(PermissionCatalog::MANAGER);
        $main = $this->mainLocation();
        $item = $this->doughnutItem();
        $this->postStock($actor, $main, $item, '1');

        $codes = $this->race(2, fn () => app(InventoryLedger::class)->post($actor, [
            new StockChange($main->id, $item->id, InventoryMovementType::Sale, Quantity::fromString('1')),
        ]));

        $this->assertSame([0, 1], $codes, 'Exactly one sale must succeed and the other must see no stock.');
        $this->assertSame('0.000', $this->onHand($main, $item));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count());
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    public function test_parallel_receipts_creating_the_same_balance_row_all_succeed(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }

        $this->seedAccess();
        $actor = $this->staff(PermissionCatalog::MANAGER);
        $main = $this->mainLocation();
        $item = $this->doughnutItem();

        // No balance row exists yet: every process races to create it.
        $codes = $this->race(4, fn () => app(InventoryLedger::class)->post($actor, [
            new StockChange($main->id, $item->id, InventoryMovementType::ProductionOutput, Quantity::fromString('6')),
        ]));

        $this->assertSame([0, 0, 0, 0], $codes);
        $this->assertSame('24.000', $this->onHand($main, $item));
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    /**
     * Runs $work in $processes forked children that start together, each on
     * its own database connection. Exit codes: 0 success, 1 insufficient
     * stock, 2 anything else (printed to stderr).
     *
     * @return list<int> sorted exit codes
     */
    private function race(int $processes, callable $work): array
    {
        // Children must not share the parent's socket: each reconnects lazily.
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(500);
                }

                try {
                    $work();
                    $code = 0;
                } catch (InsufficientStock) {
                    $code = 1;
                } catch (Throwable $e) {
                    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
                    $code = 2;
                }

                // Replace the child process without running PHPUnit's shutdown handlers.
                pcntl_exec('/bin/sh', ['-c', "exit {$code}"]);
            }

            $children[] = $pid;
        }

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        sort($codes);

        return $codes;
    }
}
