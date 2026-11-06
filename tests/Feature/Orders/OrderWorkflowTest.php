<?php

namespace Tests\Feature\Orders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Domain\Shifts\ExpectedCash;
use App\Domain\Shifts\ShiftService;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 5: T-ORD-1..4, GCash checks (FR-041, FR-046–FR-052, BR-019; D-123–D-125). */
class OrderWorkflowTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $cashier;

    private User $manager;

    private User $prep;

    /** @var array{product: Product, iced: ProductVariant, hot: ProductVariant, shot: ProductOption, oat: ProductOption, group: OptionGroup} */
    private array $latte;

    private Product $doughnut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->latte = $this->coffee();
        $this->doughnut = $this->doughnut('44.00', '10');
        $this->cashier = $this->cashierOnShift();
        $this->manager = $this->staff(PermissionCatalog::MANAGER, $this->mainLocation());
        $this->prep = $this->staff(PermissionCatalog::PREPARATION_STAFF, $this->mainLocation());
    }

    /**
     * A latte (made to order → goes to preparation) plus two doughnuts.
     *
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $payment = ['method' => 'CASH', 'amount_tendered' => '500'], array $extra = [], ?User $cashier = null): Order
    {
        $this->checkout($cashier ?? $this->cashier, $this->payload([
            ['product_id' => $this->latte['product']->id, 'variant_id' => $this->latte['iced']->id, 'quantity' => 1, 'notes' => 'less ice'],
            ['product_id' => $this->doughnut->id, 'quantity' => 2],
        ], $payment, $extra))->assertSessionHasNoErrors()->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function advance(User $user, Order $order, string $from): TestResponse
    {
        return $this->actingAs($user)->post("/orders/{$order->id}/advance", ['from' => $from]);
    }

    private function stockItem(): InventoryItem
    {
        /** @var InventoryItem */
        return $this->doughnut->inventoryItem;
    }

    public function test_preparation_moves_one_step_at_a_time_and_records_who(): void
    {
        $order = $this->sell();
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        $this->advance($this->prep, $order, 'confirmed')->assertSessionHasNoErrors();
        $this->advance($this->prep, $order, 'preparing')->assertSessionHasNoErrors();
        $this->advance($this->prep, $order, 'ready')->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
        $steps = OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['confirmed', 'preparing', 'ready', 'completed'], $steps->map(fn ($h) => $h->to_status->value)->all());
        $this->assertSame([$this->cashier->id, $this->prep->id, $this->prep->id, $this->prep->id], $steps->pluck('changed_by')->all());

        // Nothing moves on from completed.
        $this->advance($this->prep, $order, 'completed')->assertSessionHasErrors('status');
    }

    public function test_a_second_tap_on_a_stale_screen_changes_nothing(): void
    {
        $order = $this->sell();
        $this->advance($this->prep, $order, 'confirmed')->assertSessionHasNoErrors();

        // Another tablet still shows "New" and taps again.
        $this->advance($this->manager, $order, 'confirmed')->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Preparing, $order->refresh()->status);
        $this->assertSame(2, OrderStatusHistory::query()->where('order_id', $order->id)->count());
    }

    public function test_preparation_staff_cannot_cancel_or_check_payments(): void
    {
        $order = $this->sell(['method' => 'GCASH', 'reference' => 'GC12345678']);
        $payment = Payment::query()->sole();

        $this->actingAs($this->prep)->post("/orders/{$order->id}/cancel", ['reason' => 'Test', 'return_stock' => true])->assertForbidden();
        $this->actingAs($this->prep)->post("/payments/{$payment->id}/verify")->assertForbidden();
        $this->actingAs($this->cashier)->post("/orders/{$order->id}/advance", ['from' => 'confirmed'])->assertForbidden();
        $this->actingAs($this->cashier)->post("/orders/{$order->id}/cancel", ['reason' => 'Test', 'return_stock' => true])->assertForbidden();

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_cancelling_needs_a_reason_refunds_the_cash_and_returns_stock(): void
    {
        $order = $this->sell();
        $shift = $this->shiftOf($this->cashier);
        $this->assertSame('8.000', $this->onHand($this->mainLocation(), $this->stockItem()));
        $this->assertTrue(app(ExpectedCash::class)->for($shift)->equals(Money::fromDecimal('1198.00')));

        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => '', 'return_stock' => true])->assertSessionHasErrors('reason');
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Customer left before preparation', 'return_stock' => true])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame($this->manager->id, $order->cancelled_by);
        $this->assertSame('Customer left before preparation', $order->cancellation_reason);
        $this->assertSame(PaymentStatus::Refunded, Payment::query()->sole()->status);

        // The cash went back out of the drawer, and the doughnuts back on the shelf.
        $this->assertTrue(app(ExpectedCash::class)->for($shift)->equals(Money::fromDecimal('1000.00')));
        $this->assertSame('10.000', $this->onHand($this->mainLocation(), $this->stockItem()));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::ReturnIn)->where('reference_id', $order->id)->count());

        $history = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('Customer left before preparation', $history->notes);
        $log = AuditLog::query()->where('event', 'order.cancelled')->sole();
        $this->assertSame('Customer left before preparation', $log->new_values['reason']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refunded', 'auditable_id' => Payment::query()->sole()->id]);

        // Cancelling twice is refused.
        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Again', 'return_stock' => true])->assertSessionHasErrors('reason');
    }

    public function test_stock_stays_out_when_the_goods_cannot_be_sold_again(): void
    {
        $order = $this->sell();

        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Dropped the box', 'return_stock' => false])->assertSessionHasNoErrors();

        $this->assertSame('8.000', $this->onHand($this->mainLocation(), $this->stockItem()));
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::ReturnIn)->count());
    }

    public function test_completed_orders_and_cash_from_closed_shifts_are_refunded_not_cancelled(): void
    {
        $completed = $this->sell();
        foreach (['confirmed', 'preparing', 'ready'] as $from) {
            $this->advance($this->prep, $completed, $from)->assertSessionHasNoErrors();
        }
        $this->actingAs($this->manager)->post("/orders/{$completed->id}/cancel", ['reason' => 'Too late', 'return_stock' => true])->assertSessionHasErrors('reason');
        $this->assertSame(OrderStatus::Completed, $completed->refresh()->status);

        $cash = $this->sell();
        $gcash = $this->sell(['method' => 'GCASH', 'reference' => 'GC99887766']);
        $shift = $this->shiftOf($this->cashier);
        app(ShiftService::class)->close($this->cashier, $shift, app(ExpectedCash::class)->for($shift), null);

        $this->actingAs($this->manager)->post("/orders/{$cash->id}/cancel", ['reason' => 'After close', 'return_stock' => true])->assertSessionHasErrors('reason');
        $this->assertSame(OrderStatus::Confirmed, $cash->refresh()->status);

        // GCash never sat in the drawer, so it can still be cancelled.
        $this->actingAs($this->manager)->post("/orders/{$gcash->id}/cancel", ['reason' => 'After close', 'return_stock' => true])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Cancelled, $gcash->refresh()->status);
    }

    public function test_preparation_board_shows_only_this_locations_active_orders(): void
    {
        $order = $this->sell();
        $readyMade = $this->checkout($this->cashier, $this->payload([['product_id' => $this->doughnut->id, 'quantity' => 1]]));
        $readyMade->assertRedirect();

        $popUp = Branch::factory()->popUp()->create(['code' => 'POP01']);
        $popUpDoughnut = $this->doughnut('44.00', '5', $popUp, 'Pop Glazed');
        $popUpCashier = $this->cashierOnShift($popUp);
        $this->checkout($popUpCashier, $this->payload([['product_id' => $popUpDoughnut->id, 'quantity' => 1]]))->assertRedirect();
        $popUpOrder = Order::query()->where('branch_id', $popUp->id)->sole();

        $this->actingAs($this->prep)->get('/staff/preparation')->assertOk()
            ->assertInertia(fn ($page) => $page->component('staff/Preparation')
                ->where('site.code', 'BRV01')
                ->has('board.new', 1)
                ->where('board.new.0.order_number', $order->order_number)
                ->where('board.new.0.next_status', 'preparing')
                ->where('board.new.0.items.0.name', 'Latte')
                ->where('board.new.0.items.0.made_to_order', true)
                ->where('board.new.0.items.0.notes', 'less ice')
                ->where('board.new.0.items.1.made_to_order', false)
                ->has('board.preparing', 0)
                ->has('board.ready', 0)
                ->where('canCancel', false));

        // The pop-up's order is not this person's to touch.
        $this->advance($this->prep, $popUpOrder, 'completed')->assertNotFound();
    }

    public function test_advance_pickups_wait_under_scheduled_until_an_hour_before(): void
    {
        $later = $this->sell(extra: ['order_type' => 'advance_pickup', 'pickup_at' => BusinessClock::now()->addHours(3)->format('Y-m-d H:i')]);
        $soon = $this->sell(extra: ['order_type' => 'advance_pickup', 'pickup_at' => BusinessClock::now()->addMinutes(30)->format('Y-m-d H:i')]);
        $walkIn = $this->sell();

        $this->actingAs($this->prep)->get('/staff/preparation')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('board.scheduled', 1)
                ->where('board.scheduled.0.id', $later->id)
                ->has('board.new', 2)
                // Due pickups first, then walk-ins in arrival order.
                ->where('board.new.0.id', $soon->id)
                ->where('board.new.1.id', $walkIn->id));
    }

    public function test_manager_confirms_or_rejects_a_gcash_payment(): void
    {
        $first = $this->sell(['method' => 'GCASH', 'reference' => 'GC11112222']);
        $second = $this->sell(['method' => 'GCASH', 'reference' => 'GC33334444']);
        $this->assertSame(OrderPaymentStatus::PendingVerification, $first->payment_status);
        [$p1, $p2] = Payment::query()->orderBy('id')->get()->all();

        $this->actingAs($this->manager)->get('/staff/payments')->assertOk()
            ->assertInertia(fn ($page) => $page->component('payments/Pending')->has('payments', 2)
                ->where('payments.0.reference_number', 'GC11112222'));

        $this->actingAs($this->manager)->post("/payments/{$p1->id}/verify")->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Verified, $p1->refresh()->status);
        $this->assertSame($this->manager->id, $p1->verified_by);
        $this->assertSame(OrderPaymentStatus::Paid, $first->refresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.verified', 'auditable_id' => $p1->id, 'user_id' => $this->manager->id]);
        $this->actingAs($this->manager)->post("/payments/{$p1->id}/verify")->assertSessionHasErrors('payment');

        $this->actingAs($this->manager)->post("/payments/{$p2->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post("/payments/{$p2->id}/reject", ['reason' => 'No such transfer in the app'])->assertSessionHasNoErrors();
        $second->refresh();
        $this->assertSame(PaymentStatus::Failed, $p2->refresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $second->payment_status);
        $this->assertSame($second->grand_total, $second->amount_due);
        $this->assertSame('0.00', $second->amount_paid);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.rejected', 'auditable_id' => $p2->id]);

        $this->actingAs($this->manager)->get('/staff/payments')->assertInertia(fn ($page) => $page->has('payments', 0));
    }

    public function test_order_page_offers_only_the_actions_the_user_may_take(): void
    {
        $order = $this->sell(['method' => 'GCASH', 'reference' => 'GC55556666']);

        $this->actingAs($this->cashier)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.status', 'confirmed')
                ->where('workflow.next_status', 'preparing')
                ->where('workflow.can_advance', false)
                ->where('workflow.can_cancel', false)
                ->where('workflow.can_verify', false)
                ->has('workflow.pending_payments', 1)
                ->has('workflow.history', 1));

        $this->actingAs($this->manager)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.can_advance', true)
                ->where('workflow.can_cancel', true)
                ->where('workflow.can_verify', true));
    }
}
