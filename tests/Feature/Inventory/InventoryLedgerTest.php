<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\Concerns\InteractsWithStock;
use Tests\TestCase;

/** FR-054, FR-055, FR-062, BR-011, BR-013; D-047, D-048, D-063 · T-INV-1, 2, 8, 9 */
class InventoryLedgerTest extends TestCase
{
    use InteractsWithStaff;
    use InteractsWithStock;
    use RefreshDatabase;

    private User $actor;

    private InventoryLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actor = $this->staff(PermissionCatalog::MANAGER);
        $this->ledger = app(InventoryLedger::class);
    }

    public function test_every_change_is_a_movement_and_the_balance_equals_their_sum(): void
    {
        $main = $this->mainLocation();
        $item = $this->doughnutItem();

        $this->postStock($this->actor, $main, $item, '24');
        $this->postStock($this->actor, $main, $item, '5', InventoryMovementType::Sale);
        $this->postStock($this->actor, $main, $item, '2', InventoryMovementType::Waste);
        $this->postStock($this->actor, $main, $item, '12', InventoryMovementType::ProductionOutput);

        $this->assertSame('29.000', $this->onHand($main, $item));
        $movements = InventoryMovement::query()->where('inventory_item_id', $item->id)->orderBy('id')->get();
        $this->assertSame(['24.000', '-5.000', '-2.000', '12.000'], $movements->pluck('quantity_delta')->all());
        $this->assertSame(['24.000', '19.000', '17.000', '29.000'], $movements->pluck('balance_after')->all());
        $this->assertSame($this->actor->id, $movements->first()?->created_by);
        $this->assertTrue($this->ledger->reconcile()->isEmpty());
    }

    public function test_negative_stock_is_refused_and_nothing_from_the_batch_is_written(): void
    {
        $main = $this->mainLocation();
        $glazed = $this->doughnutItem();
        $choco = $this->doughnutItem();
        $this->postStock($this->actor, $main, $glazed, '10');
        $this->postStock($this->actor, $main, $choco, '1');

        try {
            $this->ledger->post($this->actor, [
                new StockChange($main->id, $glazed->id, InventoryMovementType::Sale, Quantity::fromString('3')),
                new StockChange($main->id, $choco->id, InventoryMovementType::Sale, Quantity::fromString('2')),
            ]);
            $this->fail('Expected InsufficientStock.');
        } catch (InsufficientStock $e) {
            $this->assertSame($choco->id, $e->inventoryItemId);
            $this->assertSame('1.000', $e->available);
        }

        $this->assertSame('10.000', $this->onHand($main, $glazed));
        $this->assertSame('1.000', $this->onHand($main, $choco));
        $this->assertSame(2, InventoryMovement::query()->count());
    }

    public function test_the_same_item_twice_in_one_batch_uses_the_running_balance(): void
    {
        $main = $this->mainLocation();
        $item = $this->doughnutItem();
        $this->postStock($this->actor, $main, $item, '3');

        $this->expectException(InsufficientStock::class);
        $this->ledger->post($this->actor, [
            new StockChange($main->id, $item->id, InventoryMovementType::Sale, Quantity::fromString('2')),
            new StockChange($main->id, $item->id, InventoryMovementType::Sale, Quantity::fromString('2')),
        ]);
    }

    public function test_negative_stock_can_be_allowed_by_configuration(): void
    {
        config(['brevon.allow_negative_stock' => true]);
        $main = $this->mainLocation();
        $item = $this->doughnutItem();

        $this->postStock($this->actor, $main, $item, '2', InventoryMovementType::Sale);

        $this->assertSame('-2.000', $this->onHand($main, $item));
        $this->assertTrue($this->ledger->reconcile()->isEmpty());
    }

    public function test_balances_are_kept_per_location(): void
    {
        $main = $this->mainLocation();
        $popUp = Branch::factory()->popUp()->create();
        $item = $this->doughnutItem();

        $this->postStock($this->actor, $main, $item, '50');
        $this->postStock($this->actor, $popUp, $item, '12');
        $this->postStock($this->actor, $popUp, $item, '4', InventoryMovementType::Sale);

        $this->assertSame('50.000', $this->onHand($main, $item));
        $this->assertSame('8.000', $this->onHand($popUp, $item));
    }

    public function test_transfers_are_rejected_until_dispatch_exists(): void
    {
        $main = $this->mainLocation();
        $item = $this->doughnutItem();
        $this->postStock($this->actor, $main, $item, '10');

        foreach ([InventoryMovementType::TransferOut, InventoryMovementType::TransferIn] as $type) {
            try {
                $this->postStock($this->actor, $main, $item, '1', $type);
                $this->fail("{$type->value} should be rejected.");
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertEmpty(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'transfer')));
    }

    public function test_quantities_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->postStock($this->actor, $this->mainLocation(), $this->doughnutItem(), '0');
    }

    public function test_movements_are_append_only(): void
    {
        $main = $this->mainLocation();
        $item = $this->doughnutItem();
        $this->postStock($this->actor, $main, $item, '5');
        $movement = InventoryMovement::query()->firstOrFail();

        try {
            $movement->forceFill(['quantity_delta' => '50'])->save();
            $this->fail('Movement update should throw.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_weighted_average_cost_is_kept_on_receipts(): void
    {
        $main = $this->mainLocation();
        $flour = $this->ingredientItem();

        $this->ledger->post($this->actor, [new StockChange($main->id, $flour->id, InventoryMovementType::Opening, Quantity::fromString('1000'), '0.05')]);
        $this->ledger->post($this->actor, [new StockChange($main->id, $flour->id, InventoryMovementType::PurchaseReceipt, Quantity::fromString('3000'), '0.07')]);

        // (1000 × 0.05 + 3000 × 0.07) / 4000 = 0.065
        $this->assertSame('0.0650', BranchInventory::query()->where('inventory_item_id', $flour->id)->value('average_unit_cost'));
    }

    public function test_reconcile_reports_a_balance_that_was_changed_outside_the_ledger(): void
    {
        $main = $this->mainLocation();
        $item = $this->doughnutItem();
        $this->postStock($this->actor, $main, $item, '10');

        DB::table('branch_inventory')->where('inventory_item_id', $item->id)->update(['quantity_on_hand' => '11.000']);

        $mismatches = $this->ledger->reconcile();
        $this->assertCount(1, $mismatches);
        $this->assertSame(['branch_id' => $main->id, 'inventory_item_id' => $item->id, 'balance' => '11.000', 'movements' => '10.000'], $mismatches->first());

        $this->artisan('brevon:inventory:reconcile')->assertFailed();
        DB::table('branch_inventory')->where('inventory_item_id', $item->id)->update(['quantity_on_hand' => '10.000']);
        $this->artisan('brevon:inventory:reconcile')->assertSuccessful();
    }
}
