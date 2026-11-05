<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Sales\CartLine;
use App\Domain\Sales\CheckoutData;
use App\Domain\Sales\CheckoutService;
use App\Domain\Sales\PaymentInput;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shifts\ShiftService;
use App\Enums\OrderType;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency for checkout (T-POS concurrency, BR-016): forked
 * processes with their own MySQL connections check out at the same moment.
 */
#[Group('concurrency')]
class CheckoutConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithCheckout;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_cashiers_selling_the_last_doughnut_only_one_succeeds(): void
    {
        $this->requiresFork();
        $this->setUpCheckout();
        $doughnut = $this->doughnut('44.00', '1');
        [$a, $b] = $this->cashiersOnShifts(2);

        $codes = $this->race([
            fn () => $this->sell($a, $doughnut),
            fn () => $this->sell($b, $doughnut),
        ]);

        $this->assertSame([0, 1], $codes, 'Exactly one sale must succeed; the other must be told there is no stock.');
        $this->assertSame(1, Order::query()->count());
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->assertSame('0.000', $this->onHand($this->mainLocation(), $item));
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    public function test_simultaneous_sales_get_unique_consecutive_numbers_without_deadlocks(): void
    {
        $this->requiresFork();
        $this->setUpCheckout();
        $doughnut = $this->doughnut('44.00', '100');
        $cashiers = $this->cashiersOnShifts(4);

        // First sale of the day for everyone: the number counter does not exist yet.
        $codes = $this->race(array_map(fn (User $c) => fn () => $this->sell($c, $doughnut), $cashiers));

        $this->assertSame([0, 0, 0, 0], $codes);
        $numbers = Order::query()->orderBy('order_number')->pluck('order_number')->map(fn (string $n) => substr($n, -4))->all();
        $this->assertSame(['0001', '0002', '0003', '0004'], $numbers);
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->assertSame('96.000', $this->onHand($this->mainLocation(), $item));
    }

    public function test_a_double_submitted_sale_is_recorded_once(): void
    {
        $this->requiresFork();
        $this->setUpCheckout();
        $doughnut = $this->doughnut('44.00', '10');
        [$cashier] = $this->cashiersOnShifts(1);
        $key = (string) Str::uuid();

        $codes = $this->race([
            fn () => $this->sell($cashier, $doughnut, $key),
            fn () => $this->sell($cashier, $doughnut, $key),
        ]);

        $this->assertSame([0, 0], $codes, 'Both requests answer with the same order.');
        $this->assertSame(1, Order::query()->count());
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->assertSame('9.000', $this->onHand($this->mainLocation(), $item));
    }

    private function requiresFork(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
    }

    /**
     * @return list<User>
     */
    private function cashiersOnShifts(int $count): array
    {
        $cashiers = [];
        for ($i = 1; $i <= $count; $i++) {
            $terminal = Terminal::query()->firstOrCreate(
                ['branch_id' => $this->mainLocation()->id, 'terminal_code' => "POS-{$i}"],
                ['name' => "Counter {$i}", 'is_active' => true],
            );
            $cashier = $this->staff(PermissionCatalog::CASHIER);
            app(ShiftService::class)->open($cashier, $terminal, Money::fromDecimal('1000'));
            $cashiers[] = $cashier;
        }

        return $cashiers;
    }

    private function sell(User $cashier, Product $doughnut, ?string $key = null): void
    {
        app(CheckoutService::class)->checkout($cashier, new CheckoutData(
            idempotencyKey: $key ?? (string) Str::uuid(),
            orderType: OrderType::WalkIn,
            lines: [new CartLine($doughnut->id, null, Quantity::fromString('1'))],
            payment: new PaymentInput('CASH', Money::fromDecimal('100')),
        ));
    }

    /**
     * Runs each callable in its own forked process, all starting together.
     * Exit codes: 0 success, 1 validation refusal (e.g. no stock), 2 other.
     *
     * @param  list<callable(): void>  $work
     * @return list<int> sorted exit codes
     */
    private function race(array $work): array
    {
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];

        foreach ($work as $job) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(500);
                }

                try {
                    $job();
                    $code = 0;
                } catch (ValidationException) {
                    $code = 1;
                } catch (Throwable $e) {
                    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
                    $code = 2;
                }

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
