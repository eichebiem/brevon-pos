<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Shared\BusinessClock;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReceiptCondition;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\Concerns\InteractsWithStock;
use Tests\TestCase;

/** Phase 7b: suppliers, purchase orders, receiving, lots, valuation (FR-056, FR-058–FR-060, FR-063, UC-006; T-INV-4, T-PO-1; D-135–D-139). */
class PurchasingTest extends TestCase
{
    use InteractsWithStaff;
    use InteractsWithStock;
    use RefreshDatabase;

    private User $manager;

    /** Can draft orders and receive deliveries, but not approve. */
    private User $clerk;

    private Branch $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->main = $this->mainLocation();
        $this->manager = $this->staff(PermissionCatalog::MANAGER);
        $this->clerk = $this->staff(PermissionCatalog::PREPARATION_STAFF);
        $this->clerk->givePermissionTo(['purchase-orders.create', 'purchase-orders.receive', 'inventory.receive']);
    }

    private function supplier(string $name = 'Mill Co'): Supplier
    {
        $this->actingAs($this->manager)->post('/admin/suppliers', ['name' => $name, 'phone' => '0917 123 4567'])->assertSessionHasNoErrors();

        return Supplier::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<array{0: InventoryItem, 1: string, 2: string}>  $lines  item, quantity, unit cost
     */
    private function draft(Supplier $supplier, array $lines, ?User $as = null): PurchaseOrder
    {
        $this->actingAs($as ?? $this->manager)->post('/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'lines' => array_map(fn (array $l) => ['inventory_item_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines),
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::query()->latest('id')->firstOrFail();
    }

    private function ordered(Supplier $supplier, array $lines): PurchaseOrder
    {
        $order = $this->draft($supplier, $lines);
        $this->actingAs($this->manager)->post("/admin/purchase-orders/{$order->id}/submit")->assertSessionHasNoErrors();

        return $order->refresh();
    }

    /**
     * @param  array<int, array<string, string>>  $lines  keyed by inventory item id
     * @param  array<string, mixed>  $extra
     */
    private function receive(PurchaseOrder $order, array $lines, User $as, array $extra = []): TestResponse
    {
        $order->load('items');

        return $this->actingAs($as)->post('/admin/receipts', [
            'purchase_order_id' => $order->id,
            'lines' => $order->items->map(fn ($l) => [
                'inventory_item_id' => $l->inventory_item_id,
                'purchase_order_item_id' => $l->id,
                'unit_cost' => $l->unit_cost,
                ...($lines[$l->inventory_item_id] ?? []),
            ])->values()->all(),
            ...$extra,
        ]);
    }

    private function lotItem(): InventoryItem
    {
        $item = $this->ingredientItem('ING-CREAM');
        $item->forceFill(['name' => 'Cream', 'track_lot' => true, 'is_perishable' => true])->save();

        return $item;
    }

    public function test_suppliers_get_codes_and_are_switched_off_not_deleted(): void
    {
        $this->actingAs($this->manager)->post('/admin/suppliers', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->post('/admin/suppliers', ['name' => 'Mill Co', 'phone' => 'call me'])->assertSessionHasErrors('phone');

        $mill = $this->supplier();
        $dairy = $this->supplier('Dairy Farm');
        $this->assertSame(['SUP001', 'SUP002'], [$mill->supplier_code, $dairy->supplier_code]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier.created', 'auditable_id' => $mill->id]);

        $this->actingAs($this->manager)->put("/admin/suppliers/{$mill->id}", ['name' => 'Mill Co', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($mill->refresh()->is_active);
        $this->assertSame('SUP001', $mill->supplier_code);

        // An inactive supplier gets no new orders.
        $flour = $this->ingredientItem();
        $this->actingAs($this->manager)->post('/admin/purchase-orders', [
            'supplier_id' => $mill->id, 'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '1000', 'unit_cost' => '0.05']],
        ])->assertSessionHasErrors('supplier_id');

        // Only suppliers.manage reaches the supplier screens.
        $this->actingAs($this->clerk)->get('/admin/suppliers')->assertForbidden();
    }

    public function test_a_draft_needs_a_manager_to_approve_it_and_is_fixed_once_ordered(): void
    {
        $supplier = $this->supplier();
        $flour = $this->ingredientItem();
        $sugar = $this->ingredientItem('ING-SUGAR');
        $doughnut = $this->doughnutItem();

        // Doughnuts are baked, not bought; quantities and costs are checked.
        $this->actingAs($this->clerk)->post('/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'lines' => [
                ['inventory_item_id' => $doughnut->id, 'quantity' => '10', 'unit_cost' => '5'],
                ['inventory_item_id' => $flour->id, 'quantity' => '0', 'unit_cost' => '0.05'],
                ['inventory_item_id' => $sugar->id, 'quantity' => '500', 'unit_cost' => '0.12345'],
            ],
        ])->assertSessionHasErrors(['lines.0.quantity', 'lines.1.quantity', 'lines.2.unit_cost']);

        $order = $this->draft($supplier, [[$flour, '25000', '0.0525'], [$sugar, '5000.5', '0.08']], $this->clerk);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertMatchesRegularExpression('/^BRV01-\d{8}-PO01$/', $order->po_number);
        $this->assertSame('1712.54', $order->subtotal, '25000 × 0.0525 = 1312.50; 5000.5 × 0.08 = 400.04');
        $this->assertSame(0, InventoryMovement::query()->count(), 'An order moves no stock.');

        // The clerk edits the draft but cannot send it.
        $this->actingAs($this->clerk)->put("/admin/purchase-orders/{$order->id}", [
            'supplier_id' => $supplier->id, 'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '20000', 'unit_cost' => '0.05']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('1000.00', $order->refresh()->subtotal);
        $this->assertSame(1, $order->items()->count());
        $this->actingAs($this->clerk)->post("/admin/purchase-orders/{$order->id}/submit")->assertForbidden();

        $this->actingAs($this->manager)->post("/admin/purchase-orders/{$order->id}/submit")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Submitted, $order->status);
        $this->assertSame($this->manager->id, $order->approved_by);
        $this->assertNotNull($order->ordered_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_order.submitted', 'auditable_id' => $order->id]);

        // Once ordered the lines are fixed.
        $this->actingAs($this->clerk)->put("/admin/purchase-orders/{$order->id}", [
            'supplier_id' => $supplier->id, 'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '1', 'unit_cost' => '0.05']],
        ])->assertSessionHasErrors('order');
        $this->actingAs($this->manager)->post("/admin/purchase-orders/{$order->id}/submit")->assertSessionHasErrors('order');
        $this->expectException(LogicException::class);
        $order->forceFill(['subtotal' => '1.00'])->save();
    }

    /** T-PO-1 and T-INV-4: partial then full delivery; the average cost follows what was paid. */
    public function test_partial_then_full_receipt_updates_the_order_and_average_cost(): void
    {
        $supplier = $this->supplier();
        $flour = $this->ingredientItem();
        $order = $this->ordered($supplier, [[$flour, '1000', '0.05']]);

        $this->receive($order, [$flour->id => ['quantity' => '600']], $this->clerk, ['delivery_reference' => 'DR-1001'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        $this->assertSame('600.000', $order->items()->firstOrFail()->quantity_received);
        $this->assertSame('600.000', $this->onHand($this->main, $flour));
        $this->assertSame('0.0500', $this->averageCost($flour));

        $receipt = GoodsReceipt::query()->sole();
        $this->assertMatchesRegularExpression('/^BRV01-\d{8}-GR01$/', $receipt->receipt_number);
        $this->assertSame('DR-1001', $receipt->supplier_delivery_reference);
        $this->assertSame($supplier->id, $receipt->supplier_id);
        $movement = InventoryMovement::query()->sole();
        $this->assertSame(InventoryMovementType::PurchaseReceipt, $movement->movement_type);
        $this->assertSame(['goods_receipt', $receipt->id, '0.0500'], [$movement->reference_type, $movement->reference_id, $movement->unit_cost]);

        // The rest arrives dearer: (600 × 0.05 + 400 × 0.06) / 1000 = 0.054.
        $this->receive($order, [$flour->id => ['quantity' => '400', 'unit_cost' => '0.06']], $this->clerk)->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);
        $this->assertSame('1000.000', $this->onHand($this->main, $flour));
        $this->assertSame('0.0540', $this->averageCost($flour));
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
        $this->assertDatabaseHas('audit_logs', ['event' => 'goods_receipt.received', 'auditable_type' => 'goods_receipt']);

        // A received order takes nothing more.
        $this->receive($order, [$flour->id => ['quantity' => '1']], $this->manager)->assertSessionHasErrors('order');
        $this->assertSame(2, GoodsReceipt::query()->count());
        $this->expectException(LogicException::class);
        $receipt->forceFill(['notes' => 'edited'])->save();
    }

    /** UC-006: over-delivery needs a manager to accept it, with a note. */
    public function test_taking_more_than_ordered_needs_a_manager_and_a_note(): void
    {
        $flour = $this->ingredientItem();
        $order = $this->ordered($this->supplier(), [[$flour, '1000', '0.05']]);

        $this->receive($order, [$flour->id => ['quantity' => '1200']], $this->clerk)->assertSessionHasErrors('lines.0.quantity');
        $this->receive($order, [$flour->id => ['quantity' => '1200']], $this->clerk, ['accept_over' => true])->assertSessionHasErrors('accept_over');
        $this->receive($order, [$flour->id => ['quantity' => '1200']], $this->manager, ['accept_over' => true])->assertSessionHasErrors('notes');
        $this->assertSame(0, GoodsReceipt::query()->count());

        $this->receive($order, [$flour->id => ['quantity' => '1200']], $this->manager, ['accept_over' => true, 'notes' => 'Supplier sent a full sack; kept'])->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, GoodsReceipt::query()->sole()->over_receipt_approved_by);
        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);
        $this->assertSame('1200.000', $this->onHand($this->main, $flour));
    }

    public function test_damaged_goods_are_noted_but_not_added_to_stock(): void
    {
        $flour = $this->ingredientItem();
        $order = $this->ordered($this->supplier(), [[$flour, '1000', '0.05']]);

        $this->receive($order, [$flour->id => ['quantity' => '800', 'rejected_quantity' => '200']], $this->clerk)->assertSessionHasErrors('lines.0.rejected_condition');
        $this->receive($order, [$flour->id => ['quantity' => '800', 'rejected_quantity' => '200', 'rejected_condition' => 'damaged']], $this->clerk)->assertSessionHasNoErrors();

        $this->assertSame('800.000', $this->onHand($this->main, $flour));
        $this->assertSame('800.000', $order->items()->firstOrFail()->quantity_received, 'Damaged goods still count as due.');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->refresh()->status);
        $lines = GoodsReceiptItem::query()->orderBy('id')->get();
        $this->assertSame([ReceiptCondition::Accepted, ReceiptCondition::Damaged], $lines->pluck('condition')->all());
        $this->assertSame(1, InventoryMovement::query()->count());

        // A delivery that was all damaged adds nothing.
        $this->receive($order, [$flour->id => ['rejected_quantity' => '200', 'rejected_condition' => 'expired']], $this->clerk)->assertSessionHasNoErrors();
        $this->assertSame('800.000', $this->onHand($this->main, $flour));
    }

    /** FR-060: lot-tracked items need a lot or expiry, and lots are used up first-expiring-first. */
    public function test_lots_are_recorded_and_used_up_first_expiring_first(): void
    {
        $cream = $this->lotItem();
        $order = $this->ordered($this->supplier(), [[$cream, '20', '2.50']]);
        $today = BusinessClock::today();

        $this->receive($order, [$cream->id => ['quantity' => '10']], $this->clerk)->assertSessionHasErrors('lines.0.lot_number');
        $this->receive($order, [$cream->id => ['quantity' => '10', 'expires_at' => $today->subDay()->toDateString()]], $this->clerk)->assertSessionHasErrors('lines.0.expires_at');
        $this->receive($order, [$cream->id => ['quantity' => '10', 'expires_at' => '2026-02-30']], $this->clerk)->assertSessionHasErrors('lines.0.expires_at');

        $this->receive($order, [$cream->id => ['quantity' => '10', 'lot_number' => 'L-LATE', 'expires_at' => $today->addDays(30)->toDateString()]], $this->clerk)->assertSessionHasNoErrors();
        $this->receive($order, [$cream->id => ['quantity' => '10', 'lot_number' => 'L-SOON', 'expires_at' => $today->addDays(3)->toDateString()]], $this->clerk)->assertSessionHasNoErrors();

        $late = InventoryLot::query()->where('lot_number', 'L-LATE')->sole();
        $soon = InventoryLot::query()->where('lot_number', 'L-SOON')->sole();
        $this->assertSame($soon->id, InventoryMovement::query()->latest('id')->firstOrFail()->inventory_lot_id);
        $this->assertSame($soon->id, GoodsReceiptItem::query()->latest('id')->firstOrFail()->inventory_lot_id);

        // 12 used: the lot expiring first goes first.
        $this->postStock($this->manager, $this->main, $cream, '12', InventoryMovementType::ProductionConsumption);
        $this->assertSame(['0.000', '8.000'], [$soon->refresh()->quantity_remaining, $late->refresh()->quantity_remaining]);

        $this->actingAs($this->manager)->get('/admin/inventory/lots')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/inventory/Lots')
                ->where('counts.expiring', 0)
                ->where('lots.total', 0));
        $this->postStock($this->manager, $this->main, $cream, '8', InventoryMovementType::Waste);
        $this->assertSame('0.000', $late->refresh()->quantity_remaining);
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());

        $this->expectException(LogicException::class);
        $late->forceFill(['expires_at' => $today->addYear()->toDateString()])->save();
    }

    public function test_expiring_lots_are_flagged_on_stock_on_hand(): void
    {
        $cream = $this->lotItem();
        $order = $this->ordered($this->supplier(), [[$cream, '5', '2.50']]);
        $this->receive($order, [$cream->id => ['quantity' => '5', 'expires_at' => BusinessClock::today()->addDays(2)->toDateString()]], $this->clerk)->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get('/admin/inventory/stock')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lots.expiring', 1)->where('lots.expired', 0));
        $this->actingAs($this->manager)->get('/admin/inventory/lots?show=expiring')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lots.data.0.state', 'expiring'));
    }

    public function test_a_delivery_can_be_received_without_an_order(): void
    {
        $sugar = $this->ingredientItem('ING-SUGAR');
        $doughnut = $this->doughnutItem();
        $line = fn (InventoryItem $i, string $q, ?string $cost = '0.08') => ['inventory_item_id' => $i->id, 'quantity' => $q, 'unit_cost' => $cost];

        $this->actingAs($this->clerk)->post('/admin/receipts', ['lines' => [$line($doughnut, '5')]])->assertSessionHasErrors('lines.0.quantity');
        $this->actingAs($this->clerk)->post('/admin/receipts', ['lines' => [$line($sugar, '500', null)]])->assertSessionHasErrors('lines.0.unit_cost');
        $this->actingAs($this->clerk)->post('/admin/receipts', ['lines' => [$line($sugar, '500')], 'notes' => 'Market run'])->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::query()->sole();
        $this->assertNull($receipt->purchase_order_id);
        $this->assertNull($receipt->supplier_id);
        $this->assertSame('500.000', $this->onHand($this->main, $sugar));

        // Without inventory.receive, only order deliveries are allowed.
        $this->clerk->revokePermissionTo('inventory.receive');
        $this->actingAs($this->clerk)->post('/admin/receipts', ['lines' => [$line($sugar, '1')]])->assertForbidden();
    }

    public function test_cancelling_keeps_what_was_received(): void
    {
        $supplier = $this->supplier();
        $flour = $this->ingredientItem();

        $draft = $this->draft($supplier, [[$flour, '100', '0.05']], $this->clerk);
        $this->actingAs($this->clerk)->post("/admin/purchase-orders/{$draft->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->clerk)->post("/admin/purchase-orders/{$draft->id}/cancel", ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $draft->refresh()->status);

        $order = $this->ordered($supplier, [[$flour, '1000', '0.05']]);
        $this->receive($order, [$flour->id => ['quantity' => '300']], $this->clerk)->assertSessionHasNoErrors();
        $this->actingAs($this->clerk)->post("/admin/purchase-orders/{$order->id}/cancel", ['reason' => 'Rest not coming'])->assertSessionHasErrors('order');
        $this->actingAs($this->manager)->post("/admin/purchase-orders/{$order->id}/cancel", ['reason' => 'Rest not coming'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);
        $this->assertSame($this->manager->id, $order->cancelled_by);
        $this->assertSame('300.000', $this->onHand($this->main, $flour));
        $this->receive($order, [$flour->id => ['quantity' => '700']], $this->clerk)->assertSessionHasErrors('order');
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_order.cancelled', 'auditable_id' => $order->id]);
    }

    /** FR-063: on hand × weighted average cost; items with no cost are flagged and left out. */
    public function test_stock_value_uses_the_weighted_average(): void
    {
        $flour = $this->ingredientItem();
        $sugar = $this->ingredientItem('ING-SUGAR');
        $order = $this->ordered($this->supplier(), [[$flour, '1000', '0.0525']]);
        $this->receive($order, [$flour->id => ['quantity' => '1000']], $this->clerk)->assertSessionHasNoErrors();
        $this->postStock($this->manager, $this->main, $sugar, '200'); // opening stock with no cost

        $this->actingAs($this->manager)->get('/admin/inventory/valuation')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/inventory/Valuation')
                ->where('total', '52.50')
                ->where('uncosted', 1)
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('sku', 'ING-FLOUR')['value'] === '52.50'
                    && collect($rows)->firstWhere('sku', 'ING-SUGAR')['value'] === null));

        $this->actingAs($this->clerk)->get('/admin/inventory/valuation')->assertForbidden();
    }

    public function test_purchasing_pages_are_limited_to_the_users_locations(): void
    {
        $flour = $this->ingredientItem();
        $order = $this->ordered($this->supplier(), [[$flour, '10', '1']]);
        $this->receive($order, [$flour->id => ['quantity' => '10']], $this->manager)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::query()->sole();

        $outsider = $this->staff(PermissionCatalog::MANAGER, Branch::factory()->popUp()->create());
        $this->actingAs($outsider)->get("/admin/purchase-orders/{$order->id}")->assertNotFound();
        $this->actingAs($outsider)->get("/admin/receipts/{$receipt->id}")->assertNotFound();
        $this->actingAs($outsider)->get('/admin/purchase-orders/create')->assertNotFound();
        $this->actingAs($outsider)->get('/admin/purchase-orders')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 0));

        $this->actingAs($this->manager)->get("/admin/purchase-orders/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/purchasing/orders/Show')
                ->where('order.lines.0.quantity_received', '10.000')
                ->where('can.receive', false));
    }

    private function averageCost(InventoryItem $item): ?string
    {
        return BranchInventory::query()->where(['branch_id' => $this->main->id, 'inventory_item_id' => $item->id])->value('average_unit_cost');
    }
}
