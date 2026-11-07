<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Sales\OrderReceipt;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 4c: cashier screen, quote preview, order history, receipts and reprints (FR-024, FR-025, FR-029, FR-033, FR-034). */
class PosScreenTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_pos_screen_has_no_catalog_without_an_open_shift(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());

        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/Index')
                ->where('catalog', null)
                ->where('paymentMethods', [])
                ->where('canOpenShift', true));
    }

    public function test_pos_screen_lists_products_prices_sizes_and_stock_left(): void
    {
        $this->doughnut('44.00', '7');
        $coffee = $this->coffee();
        $cashier = $this->cashierOnShift();

        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertInertia(function ($page) {
                $page->component('pos/Index')
                    ->where('canSell', true)
                    ->has('paymentMethods', 2)
                    ->has('catalog.products', 2);

                $products = collect($page->toArray()['props']['catalog']['products'])->keyBy('name');
                $this->assertSame('44.00', $products['Choco Custard']['price']);
                $this->assertSame('7.000', $products['Choco Custard']['on_hand']);

                $latte = $products['Latte'];
                $this->assertNull($latte['on_hand'], 'Made-to-order drinks show no stock count.');
                $this->assertTrue($latte['prices_vary']);
                $this->assertSame(['Iced 16oz', 'Hot 12oz'], array_column($latte['variants'], 'name'));
                $this->assertSame(['110.00', '120.00'], array_column($latte['variants'], 'price'));
                $this->assertArrayNotHasKey('option_groups', $latte, 'no add-ons (D-166)');
            });
    }

    public function test_pos_screen_shows_only_what_the_shift_location_sells(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $this->doughnut('44.00', '5', $popUp, 'Pop-up Glazed');
        $this->doughnut('44.00', '5', null, 'Main Only');
        $cashier = $this->cashierOnShift($popUp);

        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertInertia(fn ($page) => $page->has('catalog.products', 1)
                ->where('catalog.products.0.name', 'Pop-up Glazed'));
    }

    public function test_quote_returns_unit_totals_and_cash_change_from_the_server(): void
    {
        $coffee = $this->coffee();
        $cashier = $this->cashierOnShift();
        $line = ['product_id' => $coffee['product']->id, 'variant_id' => $coffee['hot']->id, 'quantity' => 2];

        $this->actingAs($cashier)->postJson('/pos/quote', ['items' => [$line], 'payment' => ['method' => 'CASH', 'amount_tendered' => '300']])
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', '120.00')
            ->assertJsonPath('grand_total', '240.00')
            ->assertJsonPath('change', '60.00');

        $this->actingAs($cashier)->postJson('/pos/quote', ['items' => [$line], 'payment' => ['method' => 'CASH', 'amount_tendered' => '200']])
            ->assertOk()
            ->assertJsonPath('change', null);
    }

    public function test_order_history_shows_this_shifts_orders_and_searches_by_number(): void
    {
        $product = $this->doughnut('44.00', '20');
        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertRedirect();
        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 2]]))->assertRedirect();
        $numbers = Order::query()->orderBy('id')->pluck('order_number');

        $this->actingAs($cashier)->get('/pos/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/Orders')
                ->where('scope', 'shift')
                ->has('orders', 2)
                ->where('orders.0.order_number', $numbers[1])
                ->where('orders.0.grand_total', '88.00')
                ->where('orders.0.payment_method', 'Cash'));

        $this->actingAs($cashier)->get('/pos/orders?search='.substr((string) $numbers[0], -4))->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.order_number', $numbers[0]));
    }

    public function test_staff_cannot_open_orders_of_a_location_they_are_not_assigned_to(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $product = $this->doughnut('44.00', '5', $popUp, 'Pop-up Glazed');
        $popUpCashier = $this->cashierOnShift($popUp);
        $this->checkout($popUpCashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertRedirect();
        $order = Order::query()->sole();

        $mainCashier = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());

        $this->actingAs($mainCashier)->get("/pos/orders/{$order->id}")->assertNotFound();
        $this->actingAs($mainCashier)->get("/pos/orders/{$order->id}/receipt")->assertNotFound();
        $this->actingAs($mainCashier)->post("/pos/orders/{$order->id}/reprint")->assertNotFound();
        $this->actingAs($mainCashier)->get('/pos/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders', 0));
    }

    public function test_receipt_is_printed_from_the_order_snapshots(): void
    {
        $coffee = $this->coffee();
        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload(
            [['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 1, 'notes' => 'less ice']],
            ['method' => 'CASH', 'amount_tendered' => '200'],
        ))->assertRedirect();
        $order = Order::query()->sole();

        // Renaming the product later must not change the printed receipt (BR-015).
        $coffee['product']->update(['name' => 'Renamed Latte']);

        $this->actingAs($cashier)->get("/pos/orders/{$order->id}/receipt?auto=1")->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/Receipt')
                ->where('autoPrint', true)
                ->where('receipt.title', OrderReceipt::TITLE)
                ->where('receipt.order.order_number', $order->order_number)
                ->where('receipt.order.grand_total', '110.00')
                ->where('receipt.items.0.name', 'Latte')
                ->where('receipt.items.0.variant', 'Iced 16oz')
                ->where('receipt.items.0.notes', 'less ice')
                ->where('receipt.payments.0.amount_tendered', '200.00')
                ->where('receipt.payments.0.change_amount', '90.00')
                ->where('receipt.location.name', $this->mainLocation()->name));

        $this->actingAs($cashier)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/OrderShow')->where('receipt.order.id', $order->id));
    }

    public function test_reprinting_only_writes_an_audit_entry(): void
    {
        $product = $this->doughnut('44.00', '5');
        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertRedirect();
        $order = Order::query()->sole();
        $before = [Order::query()->count(), OrderItem::query()->count(), Payment::query()->count(), DB::table('inventory_movements')->count()];

        $this->actingAs($cashier)->from("/pos/orders/{$order->id}")->post("/pos/orders/{$order->id}/reprint")
            ->assertRedirect("/pos/orders/{$order->id}");

        $this->assertSame($before, [Order::query()->count(), OrderItem::query()->count(), Payment::query()->count(), DB::table('inventory_movements')->count()]);
        $log = AuditLog::query()->where('event', 'order.receipt_reprinted')->sole();
        $this->assertSame($order->id, $log->auditable_id);
        $this->assertSame($cashier->id, $log->user_id);
    }

    public function test_order_pages_need_the_view_orders_permission(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());
        $user->syncRoles([]);

        $this->actingAs($user)->get('/pos/orders')->assertForbidden();
    }
}
