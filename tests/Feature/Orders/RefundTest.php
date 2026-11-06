<?php

namespace Tests\Feature\Orders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Domain\Shifts\ExpectedCash;
use App\Domain\Shifts\ShiftService;
use App\Enums\CashMovementType;
use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 9b: refunds and voids (FR-041–FR-045, BR-005, UC-005; T-RFD-1..4; D-150–D-155). */
class RefundTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    private Product $choco;

    private Product $glazed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->manager = $this->staff(PermissionCatalog::MANAGER, null, ['username' => 'mgr', 'password' => 'secret-pass']);
        $this->cashier = $this->cashierOnShift();
        $this->choco = $this->doughnut('44.00', '10');
        $this->glazed = $this->doughnut('35.00', '10', name: 'Glazed');
    }

    /** 3 Choco (132.00) + 1 Glazed (35.00) less 12.5% = 146.12, paid in cash. */
    private function sale(array $payment = ['method' => 'CASH', 'amount_tendered' => '200']): Order
    {
        $promo = new Discount;
        $promo->forceFill(['name' => 'Promo', 'kind' => DiscountKind::Standard, 'discount_type' => DiscountType::Percentage, 'value' => '12.50', 'scope' => DiscountScope::Order, 'is_active' => true])->save();

        $this->checkout($this->cashier, $this->payload(
            [['product_id' => $this->choco->id, 'quantity' => 3], ['product_id' => $this->glazed->id, 'quantity' => 1]],
            $payment,
            ['discount' => ['id' => $promo->id], 'expected_total' => '146.12'],
        ))->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function line(Order $order, Product $product): OrderItem
    {
        return OrderItem::query()->where('order_id', $order->id)->where('product_id', $product->id)->sole();
    }

    /**
     * @param  array<int, string|array{0: string, 1: bool}>  $lines  order item id => quantity, or [quantity, return to stock]
     * @param  array<string, mixed>  $extra
     */
    private function refund(Order $order, array $lines, array $extra = [], ?User $as = null, bool $approve = true): TestResponse
    {
        $order->refresh();
        $seen = Money::sum($order->refunds()->get()->map(fn (Refund $r) => Money::fromDecimal($r->amount)))->toDecimal();

        return $this->actingAs($as ?? $this->cashier)->post("/orders/{$order->id}/refunds", [
            'lines' => array_map(fn (int $id, string|array $q) => [
                'order_item_id' => $id,
                'quantity' => is_array($q) ? $q[0] : $q,
                'return_to_stock' => is_array($q) && $q[1],
            ], array_keys($lines), array_values($lines)),
            'reason' => 'Wrong flavour given',
            'seen_refunded' => $seen,
            ...($approve ? ['approver_username' => 'mgr', 'approver_password' => 'secret-pass'] : []),
            ...$extra,
        ]);
    }

    public function test_partial_refunds_add_up_to_the_sale_and_never_go_past_it(): void
    {
        // T-RFD-1, invariant 8.
        $order = $this->sale();
        $choco = $this->line($order, $this->choco);
        $glazed = $this->line($order, $this->glazed);

        // Choco line: 132.00 − 16.50 = 115.50 for 3 → one is 38.50.
        $this->refund($order, [$choco->id => '1'])->assertSessionHasNoErrors();
        $first = Refund::query()->sole();
        $this->assertSame(['38.50', RefundKind::Refund, 'CASH'], [$first->amount, $first->kind, $first->refund_method]);
        $this->assertMatchesRegularExpression('/^BRV01-\d{8}-R0001$/', $first->refund_number);
        $this->assertSame([OrderStatus::Completed, OrderPaymentStatus::PartiallyRefunded], [$order->refresh()->status, $order->payment_status]);

        // Asking for more than is left changes nothing.
        $this->refund($order, [$choco->id => '3'])->assertSessionHasErrors("lines.{$choco->id}");
        $this->assertSame(1, Refund::query()->count());

        // The rest of the line gets exactly what is left of it, then the Glazed.
        $this->refund($order, [$choco->id => '2', $glazed->id => '1'])->assertSessionHasNoErrors();
        $second = Refund::query()->latest('id')->firstOrFail();
        $this->assertSame('107.62', $second->amount); // 77.00 + 30.62
        $this->assertSame(['77.00', '30.62'], $second->items()->pluck('amount')->all());

        $this->assertSame('146.12', Money::fromDecimal($first->amount)->add(Money::fromDecimal($second->amount))->toDecimal());
        $order->refresh();
        $this->assertSame([OrderStatus::Refunded, OrderPaymentStatus::Refunded], [$order->status, $order->payment_status]);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => 'completed', 'to_status' => 'refunded']);

        // Already refunded in full.
        $this->refund($order, [$glazed->id => '1'])->assertSessionHasErrors('refund');
    }

    public function test_a_refund_needs_permission_a_reason_and_a_managers_approval_and_is_audited(): void
    {
        // T-RFD-2, FR-044, FR-045.
        $order = $this->sale();
        $choco = $this->line($order, $this->choco);

        $prep = $this->staff(PermissionCatalog::PREPARATION_STAFF);
        $this->refund($order, [$choco->id => '1'], as: $prep)->assertForbidden();

        $elsewhere = $this->staff(PermissionCatalog::CASHIER, Branch::factory()->popUp()->create(['code' => 'POP01']));
        $this->refund($order, [$choco->id => '1'], as: $elsewhere)->assertNotFound();

        $this->refund($order, [$choco->id => '1'], ['reason' => ''])->assertSessionHasErrors('reason');
        $this->refund($order, [$choco->id => '1'], approve: false)->assertSessionHasErrors('approver');
        $this->refund($order, [$choco->id => '1'], ['approver_password' => 'wrong'])->assertSessionHasErrors('approver');
        // The cashier cannot approve themself, and a manager of another location cannot either.
        $otherManager = $this->staff(PermissionCatalog::MANAGER, Branch::query()->where('code', 'POP01')->firstOrFail(), ['username' => 'mgr2', 'password' => 'secret-pass']);
        $this->refund($order, [$choco->id => '1'], ['approver_username' => $otherManager->username])->assertSessionHasErrors('approver');
        $this->assertSame(0, Refund::query()->count());

        $this->refund($order, [$choco->id => '1'])->assertSessionHasNoErrors();
        $refund = Refund::query()->sole();
        $this->assertSame([$this->cashier->id, $this->manager->id], [$refund->requested_by, $refund->approved_by]);

        $log = AuditLog::query()->where('event', 'refund.processed')->sole();
        $this->assertSame([$order->order_number, '38.50', 'mgr', 'Wrong flavour given'], [$log->new_values['order_number'], $log->new_values['amount'], $log->new_values['approved_by'], $log->new_values['reason']]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refunded', 'auditable_type' => 'payment']);

        // A refund up to the Store settings limit goes through without a manager.
        app(StoreSettings::class)->update($this->owner, ['name' => 'Brevon', 'receipt_footer' => null, 'refund_approval_over' => '50.00']);
        $this->refund($order, [$choco->id => '1'], approve: false)->assertSessionHasNoErrors();
        $this->assertNull(Refund::query()->latest('id')->firstOrFail()->approved_by);

        // Someone who saw an older screen is told, not allowed to refund twice.
        $this->actingAs($this->cashier)->post("/orders/{$order->id}/refunds", [
            'lines' => [['order_item_id' => $choco->id, 'quantity' => '1', 'return_to_stock' => false]],
            'reason' => 'Double tap', 'seen_refunded' => '38.50',
        ])->assertSessionHasErrors('refund');

        // Orders still being made are cancelled, not refunded.
        $coffee = $this->coffee();
        $this->checkout($this->cashier, $this->payload([['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $open = Order::query()->latest('id')->firstOrFail();
        $this->refund($open, [$open->items()->firstOrFail()->id => '1'])->assertSessionHasErrors('refund');
    }

    public function test_the_original_sale_and_payment_are_kept_and_the_refund_is_its_own_record(): void
    {
        // T-RFD-3, FR-043, BR-005.
        $order = $this->sale();
        $payment = Payment::query()->sole();
        $before = $payment->only(['amount', 'amount_tendered', 'change_amount', 'payment_method_id']);
        $totals = $order->only(['subtotal', 'discount_total', 'grand_total', 'amount_paid']);

        $this->refund($order, [$this->line($order, $this->glazed)->id => '1'])->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame($before, $payment->only(['amount', 'amount_tendered', 'change_amount', 'payment_method_id']));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame($totals, $order->refresh()->only(['subtotal', 'discount_total', 'grand_total', 'amount_paid']));
        $refund = Refund::query()->sole();
        $this->assertSame([$order->id, $payment->id], [$refund->order_id, $refund->payment_id]);

        $this->expectException(LogicException::class);
        $payment->forceFill(['amount' => '1.00'])->save();
    }

    public function test_refunds_are_never_edited(): void
    {
        $order = $this->sale();
        $this->refund($order, [$this->line($order, $this->glazed)->id => '1'])->assertSessionHasNoErrors();

        $this->expectException(LogicException::class);
        Refund::query()->sole()->forceFill(['amount' => '1.00'])->save();
    }

    public function test_cash_comes_out_of_the_refunders_drawer_and_goods_go_back_only_when_accepted(): void
    {
        // T-RFD-4, FR-077, UC-005 steps 52–53.
        $order = $this->sale();
        $shift = $this->shiftOf($this->cashier);
        $expected = app(ExpectedCash::class);
        $this->assertSame('1146.12', $expected->for($shift)->toDecimal());
        $item = InventoryItem::query()->findOrFail($this->choco->inventory_item_id);
        $this->assertSame('7.000', $this->onHand($this->mainLocation(), $item));

        $choco = $this->line($order, $this->choco);
        $this->refund($order, [$choco->id => ['2', true], $this->line($order, $this->glazed)->id => '1'])->assertSessionHasNoErrors();
        $refund = Refund::query()->sole();
        $this->assertSame('107.62', $refund->amount); // 77.00 + 30.62

        // The sale stays in cash sales; the refund comes out as a cash refund.
        $c = $expected->components($shift);
        $this->assertSame(['146.12', '107.62'], [$c['cash_sales']->toDecimal(), $c['refunds']->toDecimal()]);
        $this->assertSame('1038.50', $expected->for($shift)->toDecimal());
        $movement = CashMovement::query()->where('movement_type', CashMovementType::Refund)->sole();
        $this->assertSame(['refund', $refund->id, $shift->id], [$movement->reference_type, $movement->reference_id, $movement->cashier_shift_id]);

        // Only the ticked Choco went back; the Glazed did not.
        $this->assertSame('9.000', $this->onHand($this->mainLocation(), $item));
        $back = InventoryMovement::query()->where('movement_type', InventoryMovementType::ReturnIn)->sole();
        $this->assertSame(['refund', $refund->id, '2.000'], [$back->reference_type, $back->reference_id, $back->quantity_delta]);

        // A made drink can't go back on the shelf.
        $coffee = $this->coffee();
        $this->checkout($this->cashier, $this->payload([['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $drink = Order::query()->latest('id')->firstOrFail();
        foreach (['confirmed', 'preparing', 'ready'] as $from) {
            $this->actingAs($this->manager)->post("/orders/{$drink->id}/advance", ['from' => $from])->assertSessionHasNoErrors();
        }
        $drinkLine = $drink->items()->firstOrFail();
        $this->refund($drink, [$drinkLine->id => ['1', true]])->assertSessionHasErrors("lines.{$drinkLine->id}");

        // A manager without a shift here has no drawer to hand cash back from.
        $this->refund($drink, [$drinkLine->id => '1'], as: $this->manager, approve: false)->assertSessionHasErrors('refund');

        // Once the cashier's shift is closed, a refund needs a drawer that is open.
        app(ShiftService::class)->close($this->cashier, $shift, $expected->for($shift), null);
        $this->refund($drink, [$drinkLine->id => '1'])->assertSessionHasErrors('refund');

        $terminal = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Counter 2', 'is_active' => true]);
        app(ShiftService::class)->open($this->manager, $terminal, Money::fromDecimal('500.00'));
        $this->refund($drink, [$drinkLine->id => '1'], as: $this->manager, approve: false)->assertSessionHasNoErrors();
        $this->assertSame('390.00', $expected->for($this->shiftOf($this->manager))->toDecimal());
    }

    public function test_gcash_refunds_record_the_transfer_sent_back_and_leave_the_drawer_alone(): void
    {
        // A GCash payment is refunded only after a manager confirmed it arrived.
        $order = $this->sale(['method' => 'GCASH', 'reference' => 'GC12345678']);
        $glazedLine = $this->line($order, $this->glazed);
        $this->refund($order, [$glazedLine->id => '1'], ['reference' => 'GCBACK778899'])->assertSessionHasErrors('refund');
        $this->actingAs($this->manager)->post('/payments/'.Payment::query()->sole()->id.'/verify')->assertSessionHasNoErrors();
        $glazed = $this->line($order, $this->glazed);

        $this->refund($order, [$glazed->id => '1'])->assertSessionHasErrors('reference');
        $this->refund($order, [$glazed->id => '1'], ['reference' => 'GC-1'])->assertSessionHasErrors('reference');
        $this->refund($order, [$glazed->id => '1'], ['reference' => 'gcback778899'])->assertSessionHasNoErrors();

        $refund = Refund::query()->sole();
        $this->assertSame(['GCBACK778899', 'GCASH', null], [$refund->reference_number, $refund->refund_method, $refund->cashier_shift_id]);
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame('1000.00', app(ExpectedCash::class)->for($this->shiftOf($this->cashier))->toDecimal());
    }

    public function test_cancelling_an_order_writes_a_void_record_and_the_drawer_is_counted_once(): void
    {
        // FR-041, BR-005, D-154.
        $coffee = $this->coffee();
        $this->checkout($this->cashier, $this->payload([['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 2]]))->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Out of milk', 'return_stock' => false])->assertSessionHasNoErrors();

        $void = Refund::query()->sole();
        $this->assertSame([RefundKind::Void, '220.00', 'Out of milk', $this->manager->id], [$void->kind, $void->amount, $void->reason, $void->approved_by]);
        $this->assertSame(['2.000'], $void->items()->pluck('quantity')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.voided', 'auditable_id' => $void->id]);
        // The cancelled payment left cash sales; no extra cash refund on top.
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame('1000.00', app(ExpectedCash::class)->for($this->shiftOf($this->cashier))->toDecimal());

        $this->refund($order, [$order->items()->firstOrFail()->id => '1'])->assertSessionHasErrors('refund');
    }

    public function test_the_order_page_shows_what_is_left_to_refund_and_the_receipt_lists_refunds(): void
    {
        $order = $this->sale();
        $choco = $this->line($order, $this->choco);
        $this->refund($order, [$choco->id => '1'])->assertSessionHasNoErrors();

        $this->actingAs($this->cashier)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('pos/OrderShow')
                ->where('workflow.refund.refunded', '38.50')
                ->where('workflow.refund.is_cash', true)
                ->where('workflow.refund.has_drawer', true)
                ->where('workflow.refund.can_approve', false)
                ->where('workflow.refund.lines.0.remaining_quantity', '2.000')
                ->where('workflow.refund.lines.0.remaining_amount', '77.00')
                ->where('workflow.refund.lines.0.can_return_to_stock', true)
                ->where('workflow.refunds.0.amount', '38.50')
                ->where('workflow.refunds.0.approved_by', $this->manager->name)
                ->where('receipt.refunds.0.amount', '38.50'));

        // Someone who may not refund sees the history but no refund form.
        $prep = $this->staff(PermissionCatalog::PREPARATION_STAFF);
        $this->actingAs($prep)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workflow.refund', null)->has('workflow.refunds', 1));
    }
}
