<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\StockAdjustmentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\Concerns\InteractsWithStock;
use Tests\TestCase;

/** Phase 7a: T-INV-5, T-INV-6, low stock, stock items, dispatch (FR-056, FR-057, FR-061, FR-062, BR-011–BR-013; D-063, D-131–D-134). */
class StockControlTest extends TestCase
{
    use InteractsWithStaff;
    use InteractsWithStock;
    use RefreshDatabase;

    private User $manager;

    /** Can enter adjustments but not approve them. */
    private User $clerk;

    private Branch $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->main = $this->mainLocation();
        $this->manager = $this->staff(PermissionCatalog::MANAGER);
        $this->clerk = $this->staff($this->bakerRole());
        $this->clerk->givePermissionTo('inventory.adjust');
    }

    /**
     * @param  list<array{0: InventoryItem, 1: string}>  $lines
     * @return array<string, mixed>
     */
    private function adjustment(string $kind, array $lines, string $reason = 'Weekly count', ?Branch $location = null): array
    {
        return [
            'kind' => $kind,
            'location_id' => ($location ?? $this->main)->id,
            'reason' => $reason,
            'lines' => array_map(fn (array $l) => ['inventory_item_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
        ];
    }

    /** T-INV-5: an adjustment needs a reason, and stock moves only once it is approved. */
    public function test_counts_wait_for_approval_and_post_the_difference(): void
    {
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();
        $this->postStock($this->manager, $this->main, $glazed, '20');
        $this->postStock($this->manager, $this->main, $flour, '1000');

        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('count', [[$glazed, '17']], ''))->assertSessionHasErrors('reason');
        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('count', [[$glazed, '1.5']]))->assertSessionHasErrors('lines.0.quantity');

        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('count', [[$glazed, '17'], [$flour, '1000']]))->assertSessionHasNoErrors();
        $adjustment = StockAdjustment::query()->sole();
        $this->assertSame(StockAdjustmentStatus::Pending, $adjustment->status);
        $this->assertSame('20.000', $this->onHand($this->main, $glazed), 'Nothing moves before approval.');
        $line = $adjustment->items()->firstOrFail();
        $this->assertSame(['20.000', '17.000', '-3.000'], [$line->system_quantity, $line->counted_quantity, $line->variance_quantity]);

        // Two doughnuts sell while it waits: the count's difference (−3) still applies on top.
        $this->postStock($this->manager, $this->main, $glazed, '2', InventoryMovementType::Sale);

        // The clerk cannot approve; nobody approves their own.
        $this->actingAs($this->clerk)->post("/admin/inventory/adjustments/{$adjustment->id}/approve")->assertForbidden();

        $this->actingAs($this->manager)->post("/admin/inventory/adjustments/{$adjustment->id}/approve", ['notes' => 'Checked shelf'])->assertSessionHasNoErrors();
        $adjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::Posted, $adjustment->status);
        $this->assertSame($this->manager->id, $adjustment->approved_by);
        $this->assertSame('15.000', $this->onHand($this->main, $glazed));
        $this->assertSame('1000.000', $this->onHand($this->main, $flour), 'A line with no difference posts nothing.');
        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::AdjustmentOut)->sole();
        $this->assertSame('-3.000', $movement->quantity_delta);
        $this->assertStringContainsString($adjustment->adjustment_number, (string) $movement->reason);
        $this->assertSame('stock_adjustment', $movement->reference_type);
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_adjustment.posted', 'auditable_id' => $adjustment->id]);
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());

        // Posted adjustments never change or get decided twice.
        $this->actingAs($this->manager)->post("/admin/inventory/adjustments/{$adjustment->id}/reject", ['notes' => 'Too late'])->assertSessionHasErrors('adjustment');
        $this->expectException(LogicException::class);
        $adjustment->forceFill(['reason' => 'Changed'])->save();
    }

    public function test_an_approvers_own_adjustment_posts_at_once_and_others_cannot_self_approve(): void
    {
        $flour = $this->ingredientItem();
        $this->postStock($this->manager, $this->main, $flour, '500');

        $this->actingAs($this->manager)->post('/admin/inventory/adjustments', $this->adjustment('change', [[$flour, '250.5']], 'Sack found in storage'))->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustmentStatus::Posted, StockAdjustment::query()->sole()->status);
        $this->assertSame('750.500', $this->onHand($this->main, $flour));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::AdjustmentIn)->count());

        // Someone who cannot approve waits; gaining the permission later still does not let them approve their own.
        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('change', [[$flour, '-0.5']], 'Spilled'))->assertSessionHasNoErrors();
        $pending = StockAdjustment::query()->latest('id')->firstOrFail();
        $this->assertSame(StockAdjustmentStatus::Pending, $pending->status);

        $this->clerk->givePermissionTo('inventory.approve-adjustment');
        $this->actingAs($this->clerk)->post("/admin/inventory/adjustments/{$pending->id}/approve")->assertSessionHasErrors('adjustment');
    }

    public function test_rejecting_needs_a_note_and_removing_more_than_is_there_is_refused(): void
    {
        $glazed = $this->doughnutItem();
        $this->postStock($this->manager, $this->main, $glazed, '5');

        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('change', [[$glazed, '-6']], 'Dropped tray'))->assertSessionHasErrors('lines.0.quantity');
        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('change', [[$glazed, '0']], 'Nothing'))->assertSessionHasErrors('lines.0.quantity');

        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('change', [[$glazed, '-4']], 'Staff meal'))->assertSessionHasNoErrors();
        $adjustment = StockAdjustment::query()->sole();

        $this->actingAs($this->manager)->post("/admin/inventory/adjustments/{$adjustment->id}/reject")->assertSessionHasErrors('notes');

        // Stock sold down to 2 while it waited: removing 4 would go below zero (FR-062).
        $this->postStock($this->manager, $this->main, $glazed, '3', InventoryMovementType::Sale);
        $this->actingAs($this->manager)->post("/admin/inventory/adjustments/{$adjustment->id}/approve")->assertSessionHasErrors('adjustment');
        $this->assertSame('2.000', $this->onHand($this->main, $glazed));
        $this->assertSame(StockAdjustmentStatus::Pending, $adjustment->refresh()->status);

        $this->actingAs($this->manager)->post("/admin/inventory/adjustments/{$adjustment->id}/reject", ['notes' => 'Count again'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustmentStatus::Rejected, $adjustment->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_adjustment.rejected', 'auditable_id' => $adjustment->id]);
    }

    /** T-INV-6: waste reduces stock with a reason. */
    public function test_waste_reduces_stock_with_a_reason(): void
    {
        $glazed = $this->doughnutItem();
        $this->postStock($this->manager, $this->main, $glazed, '10');
        $line = ['inventory_item_id' => $glazed->id, 'quantity' => '3'];

        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $this->main->id, 'reason' => '', 'lines' => [$line]])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $this->main->id, 'reason' => 'Dropped', 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '11']]])->assertSessionHasErrors('lines');
        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $this->main->id, 'reason' => 'Dropped tray', 'lines' => [$line]])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/inventory/movements?type=waste');

        $this->assertSame('7.000', $this->onHand($this->main, $glazed));
        $waste = InventoryMovement::query()->where('movement_type', InventoryMovementType::Waste)->sole();
        $this->assertSame('-3.000', $waste->quantity_delta);
        $this->assertSame('Dropped tray', $waste->reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.waste', 'auditable_id' => $this->main->id]);

        $this->actingAs($this->staff(PermissionCatalog::CASHIER))->post('/admin/inventory/waste', ['location_id' => $this->main->id, 'reason' => 'X', 'lines' => [$line]])->assertForbidden();
    }

    public function test_an_ended_pop_up_with_stock_left_can_still_write_it_off(): void
    {
        $popUp = Branch::factory()->popUp(now()->subDays(5)->toDateString(), now()->subDay()->toDateString())->create(['code' => 'POP07']);
        $this->manager->branches()->attach($popUp->id);
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();
        $this->postStock($this->manager, $popUp, $glazed, '6');

        $this->actingAs($this->manager)->get("/admin/inventory/waste?location={$popUp->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('site.id', $popUp->id)->has('items', 1)->where('items.0.on_hand', '6.000'));

        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $popUp->id, 'reason' => 'Unsold at closing', 'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '1']]])->assertSessionHasErrors('lines.0.quantity');
        // An ended pop-up cannot become the working location, so the form stays on it.
        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $popUp->id, 'reason' => 'Unsold at closing', 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '6']]])
            ->assertSessionHasNoErrors()->assertRedirect("/admin/inventory/waste?location={$popUp->id}");
        $this->assertSame('0.000', $this->onHand($popUp, $glazed));

        // With nothing left, the ended pop-up is no longer offered.
        $this->actingAs($this->manager)->post('/admin/inventory/waste', ['location_id' => $popUp->id, 'reason' => 'Again', 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '1']]])->assertNotFound();
    }

    /** D-063, D-133: doughnuts move main → pop-up in one transaction at main's average cost. */
    public function test_dispatch_moves_doughnuts_from_main_to_a_pop_up(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP01']);
        $this->manager->branches()->attach($popUp->id);
        $glazed = $this->doughnutItem();
        app(InventoryLedger::class)->post($this->manager, [new StockChange($this->main->id, $glazed->id, InventoryMovementType::Opening, Quantity::fromString('40'), '12.5000')]);

        $this->actingAs($this->manager)->post('/admin/inventory/dispatches', ['to_branch_id' => $popUp->id, 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '24']]])->assertSessionHasNoErrors();
        $transfer = StockTransfer::query()->sole();
        $this->assertMatchesRegularExpression('/^BRV01-\d{8}-D01$/', $transfer->transfer_number);
        $this->assertSame('16.000', $this->onHand($this->main, $glazed));
        $this->assertSame('24.000', $this->onHand($popUp, $glazed));
        $this->assertSame('12.5000', BranchInventory::query()->where(['branch_id' => $popUp->id, 'inventory_item_id' => $glazed->id])->value('average_unit_cost'));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::TransferOut)->where('branch_id', $this->main->id)->count());
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::TransferIn)->where('branch_id', $popUp->id)->where('reference_id', $transfer->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'stock.dispatched')->count());
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());

        // Not more than main holds; whole pieces only; nothing moved by a refused dispatch.
        $this->actingAs($this->manager)->post('/admin/inventory/dispatches', ['to_branch_id' => $popUp->id, 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '17']]])->assertSessionHasErrors('lines');
        $this->actingAs($this->manager)->post('/admin/inventory/dispatches', ['to_branch_id' => $popUp->id, 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '1.5']]])->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(1, StockTransfer::query()->count());
        $this->assertSame('16.000', $this->onHand($this->main, $glazed));

        $this->actingAs($this->manager)->get("/admin/inventory/dispatches/{$transfer->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('dispatch.lines.0.quantity', '24.000'));
    }

    public function test_dispatch_is_only_for_doughnuts_to_active_pop_ups_the_user_works_at(): void
    {
        $assigned = Branch::factory()->popUp()->create(['code' => 'POP01']);
        $notAssigned = Branch::factory()->popUp()->create(['code' => 'POP02']);
        $ended = Branch::factory()->popUp(null, now()->subDays(2)->toDateString())->create(['code' => 'POP03']);
        $this->manager->branches()->attach([$assigned->id, $ended->id]);
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();
        $this->postStock($this->manager, $this->main, $glazed, '10');
        $this->postStock($this->manager, $this->main, $flour, '1000');

        $this->actingAs($this->manager)->get('/admin/inventory/dispatches/create')->assertOk()
            ->assertInertia(fn ($page) => $page->has('destinations', 1)->where('destinations.0.id', $assigned->id)->has('items', 1));

        foreach ([$notAssigned, $ended, $this->main] as $to) {
            $this->actingAs($this->manager)->post('/admin/inventory/dispatches', ['to_branch_id' => $to->id, 'lines' => [['inventory_item_id' => $glazed->id, 'quantity' => '1']]])->assertSessionHasErrors('to_branch_id');
        }
        $this->actingAs($this->manager)->post('/admin/inventory/dispatches', ['to_branch_id' => $assigned->id, 'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '1']]])->assertSessionHasErrors('lines.0.quantity');

        // The ledger still refuses transfers that are not part of a dispatch.
        $this->expectException(LogicException::class);
        $this->postStock($this->manager, $assigned, $glazed, '1', InventoryMovementType::TransferIn);
    }

    /** FR-057: items at or below their reorder level are flagged. */
    public function test_low_stock_is_flagged_by_reorder_level(): void
    {
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();
        $glazed->update(['reorder_level' => '12']);
        $this->postStock($this->manager, $this->main, $glazed, '12');
        $this->postStock($this->manager, $this->main, $flour, '5'); // reorder level 0: no alert

        $this->actingAs($this->manager)->get('/admin/inventory/stock')->assertOk()
            ->assertInertia(fn ($page) => $page->where('lowCount', 1)->has('rows', 2));
        $this->actingAs($this->manager)->get('/admin/inventory/stock?low=1')->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.id', $glazed->id)->where('rows.0.low', true));
    }

    /** D-134: ingredients are added here; doughnut items only change their stock settings. */
    public function test_stock_items_are_managed_with_linked_items_locked(): void
    {
        $gram = $this->ingredientItem()->unit_of_measure_id;
        $item = ['sku' => 'pkg-box6', 'name' => 'Box of 6', 'item_type' => 'packaging', 'unit_of_measure_id' => $gram, 'reorder_level' => '50', 'is_perishable' => false];

        $this->actingAs($this->manager)->post('/admin/inventory/items', [...$item, 'item_type' => 'finished_good'])->assertSessionHasErrors('item_type');
        $this->actingAs($this->manager)->post('/admin/inventory/items', [...$item, 'reorder_level' => '-1'])->assertSessionHasErrors('reorder_level');
        $this->actingAs($this->manager)->post('/admin/inventory/items', $item)->assertSessionHasNoErrors();
        $box = InventoryItem::query()->where('sku', 'PKG-BOX6')->sole();
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_item.created', 'auditable_id' => $box->id]);

        $glazed = $this->doughnutItem();
        $name = $glazed->name;
        $this->actingAs($this->manager)->put("/admin/inventory/items/{$glazed->id}", ['name' => 'Renamed', 'reorder_level' => '24', 'is_perishable' => true])->assertSessionHasNoErrors();
        $glazed->refresh();
        $this->assertSame($name, $glazed->name, 'Doughnut names come from the product.');
        $this->assertSame('24.000', $glazed->reorder_level);

        $this->postStock($this->manager, $this->main, $box, '10');
        $pc = InventoryItem::query()->whereKey($glazed->id)->value('unit_of_measure_id');
        $this->actingAs($this->manager)->put("/admin/inventory/items/{$box->id}", ['unit_of_measure_id' => $pc, 'reorder_level' => '5'])->assertSessionHasErrors('unit_of_measure_id');

        $this->actingAs($this->staff($this->bakerRole()))->post('/admin/inventory/items', [...$item, 'sku' => 'PKG-2'])->assertForbidden();
    }

    public function test_adjustment_pages_are_scoped_to_the_users_locations(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP05']);
        $glazed = $this->doughnutItem();
        $this->postStock($this->manager, $this->main, $glazed, '5');
        $this->actingAs($this->clerk)->post('/admin/inventory/adjustments', $this->adjustment('count', [[$glazed, '4']]))->assertSessionHasNoErrors();
        $adjustment = StockAdjustment::query()->sole();

        $outsider = $this->staff(PermissionCatalog::MANAGER, $popUp);
        $this->actingAs($outsider)->get("/admin/inventory/adjustments/{$adjustment->id}")->assertNotFound();
        $this->actingAs($outsider)->post("/admin/inventory/adjustments/{$adjustment->id}/approve")->assertNotFound();
        $this->actingAs($outsider)->post('/admin/inventory/adjustments', $this->adjustment('count', [[$glazed, '1']]))->assertNotFound();

        $this->actingAs($this->manager)->get('/admin/inventory/adjustments')->assertOk()
            ->assertInertia(fn ($page) => $page->where('pendingCount', 1)->has('adjustments.data', 1));
        $this->actingAs($this->manager)->get("/admin/inventory/adjustments/{$adjustment->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('canDecide', true)->where('adjustment.lines.0.variance_quantity', '-1.000'));
    }
}
