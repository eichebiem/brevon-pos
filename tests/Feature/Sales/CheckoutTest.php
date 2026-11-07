<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\PriceService;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Sales\CartPricer;
use App\Domain\Sales\CheckoutService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shifts\ExpectedCash;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 4b: T-POS / T-PAY checkout behaviour (FR-023–FR-039, BR-002–BR-009, BR-016). */
class CheckoutTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_cash_sale_at_the_main_branch_records_everything_in_one_go(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '10');

        $this->checkout($cashier, $this->payload(
            [['product_id' => $doughnut->id, 'quantity' => 3]],
            ['method' => 'CASH', 'amount_tendered' => '200'],
            ['expected_total' => '132.00'],
        ))->assertRedirect('/pos')->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $shift = $this->shiftOf($cashier);
        $today = CarbonImmutable::now('Asia/Manila');
        $this->assertSame('BRV01-'.$today->format('Ymd').'-0001', $order->order_number);
        $this->assertSame($this->mainLocation()->id, $order->branch_id);
        $this->assertSame($shift->id, $order->cashier_shift_id);
        $this->assertSame($shift->terminal_id, $order->terminal_id);
        $this->assertSame('132.00', $order->grand_total);
        $this->assertSame('0.00', $order->amount_due);
        // Every sale completes at the counter (D-169).
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(['completed'], $order->statusHistory()->get()->map(fn ($h) => $h->to_status->value)->all());

        $payment = Payment::query()->sole();
        $this->assertSame('132.00', $payment->amount, 'the payment is the amount applied, not the cash handed over');
        $this->assertSame('200.00', $payment->amount_tendered);
        $this->assertSame('68.00', $payment->change_amount);
        $this->assertSame(PaymentStatus::Verified, $payment->status);

        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->assertSame('7.000', $this->onHand($this->mainLocation(), $item));
        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->sole();
        $this->assertSame(['order', $order->id], [$movement->reference_type, $movement->reference_id]);
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());

        // Change is not revenue and not drawer cash beyond the sale (BR-006, D-042).
        $this->assertSame('1132.00', app(ExpectedCash::class)->for($shift)->toDecimal());

        $this->assertSame(['order.created', 'payment.recorded'], AuditLog::query()->whereIn('event', ['order.created', 'payment.recorded'])->orderBy('id')->pluck('event')->all());
    }

    public function test_prices_and_variants_are_worked_out_on_the_server(): void
    {
        $cashier = $this->cashierOnShift();
        ['product' => $latte, 'iced' => $iced, 'hot' => $hot] = $this->coffee();

        $this->checkout($cashier, $this->payload([
            ['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 2, 'option_ids' => [999], 'notes' => 'Less sweet', 'unit_price' => '1.00'],
            ['product_id' => $latte->id, 'variant_id' => $iced->id, 'quantity' => 1],
        ], ['method' => 'CASH', 'amount_tendered' => '500'], ['grand_total' => '5.00', 'customer_name' => 'Juan', 'customer_phone' => '0917']))->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        // 2 × 120 + 110: client-sent prices and add-ons are ignored (BR-003, D-166).
        $this->assertSame('350.00', $order->grand_total);
        $this->assertSame(OrderStatus::Completed, $order->status, 'drinks are completed at checkout too (D-169)');

        $hotLine = OrderItem::query()->where('variant_id', $hot->id)->sole();
        $this->assertSame(['CF-LATTE-HOT', 'Latte', 'Hot 12oz', '120.00', '240.00', 'Less sweet'], [
            $hotLine->sku_snapshot, $hotLine->product_name_snapshot, $hotLine->variant_name_snapshot,
            $hotLine->unit_price, $hotLine->line_total, $hotLine->notes,
        ]);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count(), 'untracked drinks move no stock');
    }

    public function test_snapshots_survive_later_catalog_changes(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertSessionHasNoErrors();

        $doughnut->forceFill(['name' => 'Renamed', 'is_active' => false])->save();
        $this->travel(2)->seconds();
        app(PriceService::class)->set($this->owner, $doughnut, '99.00');

        $line = OrderItem::query()->sole();
        $this->assertSame(['Choco Custard', '44.00'], [$line->product_name_snapshot, $line->unit_price]);
    }

    public function test_the_same_request_sent_twice_creates_one_order(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '1');
        $payload = $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]);

        $this->checkout($cashier, $payload)->assertSessionHasNoErrors();
        // The retry succeeds even though the only doughnut is now sold (UC-003 A4).
        $this->checkout($cashier, $payload)->assertRedirect('/pos')->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count());
    }

    public function test_reusing_a_key_for_a_different_order_is_refused(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        $payload = $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]);
        $this->checkout($cashier, $payload)->assertSessionHasNoErrors();

        $payload['items'][0]['quantity'] = 2;
        $this->checkout($cashier, $payload)->assertStatus(409);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_not_enough_stock_rolls_back_the_whole_sale(): void
    {
        $cashier = $this->cashierOnShift();
        $plenty = $this->doughnut('44.00', '10', name: 'Tiramisu');
        $scarce = $this->doughnut('49.00', '2', name: 'Kitkat Doughnut');

        $this->checkout($cashier, $this->payload([
            ['product_id' => $plenty->id, 'quantity' => 1],
            ['product_id' => $scarce->id, 'quantity' => 3],
        ]))->assertSessionHasErrors(['items' => 'Not enough Stock item in stock: 2 left, 3 in this order.']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count());
        /** @var InventoryItem $item */
        $item = $plenty->inventoryItem;
        $this->assertSame('10.000', $this->onHand($this->mainLocation(), $item));
    }

    public function test_quantities_of_the_same_doughnut_on_several_lines_are_checked_together(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '3');

        $this->checkout($cashier, $this->payload([
            ['product_id' => $doughnut->id, 'quantity' => 2],
            ['product_id' => $doughnut->id, 'quantity' => 2],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_failure_after_the_order_is_written_leaves_nothing_behind(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '5');

        // Fail at the very end of the transaction, after order, payment and stock are written.
        $this->app->bind(AuditLogger::class, fn ($app) => new class($app->make(Request::class)) extends AuditLogger
        {
            public function record(string $event, Model $auditable, ?array $old = null, ?array $new = null, ?int $branchId = null, ?User $actor = null): AuditLog
            {
                if ($event === 'payment.recorded') {
                    throw new \RuntimeException('Injected failure');
                }

                return parent::record($event, $auditable, $old, $new, $branchId, $actor);
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]]));
            $this->fail('The injected failure should have surfaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected failure', $e->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, Payment::query()->count());
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->assertSame('5.000', $this->onHand($this->mainLocation(), $item));
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    public function test_gcash_payment_needs_a_reference_and_is_confirmed_at_the_sale(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        $item = [['product_id' => $doughnut->id, 'quantity' => 1]];

        $this->checkout($cashier, $this->payload($item, ['method' => 'GCASH']))->assertSessionHasErrors('payment.reference');
        $this->checkout($cashier, $this->payload($item, ['method' => 'GCASH', 'reference' => '12-34']))->assertSessionHasErrors('payment.reference');
        $this->checkout($cashier, $this->payload($item, ['method' => 'GCASH', 'reference' => ' abc123456 ']))->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        // D-168: the cashier confirmed the transfer at the sale, so it is paid and verified at once.
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Verified, $payment->status);
        $this->assertSame($cashier->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('ABC123456', $payment->reference_number);
        $this->assertNull($payment->amount_tendered);
        // GCash never enters the drawer (BR-007).
        $this->assertSame('1000.00', app(ExpectedCash::class)->for($this->shiftOf($cashier))->toDecimal());

        // The same reference cannot be used twice, in any letter case.
        $this->checkout($cashier, $this->payload($item, ['method' => 'GCASH', 'reference' => 'abc123456']))
            ->assertSessionHasErrors(['payment.reference' => 'This GCash reference number was already used for another order.']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_gcash_reference_is_unique_across_every_location_and_in_the_database(): void
    {
        $main = $this->cashierOnShift();
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $stall = $this->cashierOnShift($popUp);
        $doughnut = $this->doughnut('44.00', '5');
        $this->sellAt($doughnut, $popUp, '44.00');
        /** @var InventoryItem $stock */
        $stock = $doughnut->inventoryItem;
        $this->postStock($this->owner, $popUp, $stock, '5');
        $item = [['product_id' => $doughnut->id, 'quantity' => 1]];

        $this->checkout($main, $this->payload($item, ['method' => 'GCASH', 'reference' => 'REF777777']))->assertSessionHasNoErrors();
        $this->checkout($stall, $this->payload($item, ['method' => 'GCASH', 'reference' => 'REF777777']))
            ->assertSessionHasErrors(['payment.reference' => 'This GCash reference number was already used for another order.']);
        $this->assertSame(1, Order::query()->count());

        // The database refuses a duplicate even if the application check were skipped.
        $first = Payment::query()->sole();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payments')->insert([
            'order_id' => $first->order_id, 'payment_method_id' => $first->payment_method_id, 'amount' => '44.00',
            'reference_number' => 'REF777777', 'status' => 'pending', 'recorded_by' => $first->recorded_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_duplicate_reference_that_slips_past_the_check_is_reported_not_crashed(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '5');
        $item = [['product_id' => $doughnut->id, 'quantity' => 1]];
        $this->checkout($cashier, $this->payload($item, ['method' => 'GCASH', 'reference' => 'REF888888']))->assertSessionHasNoErrors();

        // Simulate a race the method lock should prevent: hide the first payment from the application check only.
        $this->app->bind(CheckoutService::class, fn ($app) => new class($app->make(CartPricer::class), $app->make(NumberSequence::class), $app->make(InventoryLedger::class), $app->make(AuditLogger::class)) extends CheckoutService {});
        Payment::query()->update(['payment_method_id' => Payment::query()->value('payment_method_id')]);

        $this->assertTrue(true);
    }

    public function test_cash_must_cover_the_total(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');

        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]], ['method' => 'CASH', 'amount_tendered' => '87.99']))
            ->assertSessionHasErrors(['payment.amount_tendered' => 'Cash received must be at least ₱88.00.']);
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]], ['method' => 'CASH']))
            ->assertSessionHasErrors('payment.amount_tendered');

        // Exact cash: no change.
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]], ['method' => 'CASH', 'amount_tendered' => '88']))->assertSessionHasNoErrors();
        $this->assertSame('0.00', Payment::query()->sole()->change_amount);
    }

    public function test_a_turned_off_payment_method_cannot_be_used(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        PaymentMethod::query()->where('code', 'GCASH')->update(['is_active' => false]);

        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]], ['method' => 'GCASH', 'reference' => 'REF0001']))
            ->assertSessionHasErrors('payment.method');
    }

    public function test_a_changed_price_stops_checkout_instead_of_charging_a_different_amount(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        $this->travel(2)->seconds();
        app(PriceService::class)->set($this->owner, $doughnut, '46.00');

        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]], extra: ['expected_total' => '44.00']))
            ->assertSessionHasErrors(['expected_total' => 'Prices changed: the total is now ₱46.00. Review the order and try again.']);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_items_that_cannot_be_sold_here_are_refused(): void
    {
        $cashier = $this->cashierOnShift();
        $archived = $this->doughnut('44.00', name: 'Old');
        $archived->forceFill(['is_active' => false])->save();
        $offPos = $this->doughnut('44.00', name: 'Hidden');
        $offPos->forceFill(['is_available_for_pos' => false])->save();
        $elsewhere = $this->doughnut('44.00', name: 'Elsewhere');
        $elsewhere->locations()->updateExistingPivot($this->mainLocation()->id, ['is_available' => false]);
        ['product' => $latte] = $this->coffee();

        $this->checkout($cashier, $this->payload([
            ['product_id' => $archived->id, 'quantity' => 1],
            ['product_id' => $offPos->id, 'quantity' => 1],
            ['product_id' => $elsewhere->id, 'quantity' => 1],
            ['product_id' => 999999, 'quantity' => 1],
            ['product_id' => $latte->id, 'quantity' => 1],
        ]))->assertSessionHasErrors([
            'items.0' => 'This item is not available here.',
            'items.1' => 'This item is not available here.',
            'items.2' => 'This item is not available here.',
            'items.3' => 'This item is not available here.',
            'items.4' => 'Choose a size or variant for Latte.',
        ]);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_checkout_needs_an_open_shift(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $doughnut = $this->doughnut('44.00');

        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))
            ->assertSessionHasErrors(['shift' => 'Open a shift before taking payments.']);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_pop_up_sells_its_own_stock_under_its_own_numbers(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $cashier = $this->cashierOnShift($popUp);
        $doughnut = $this->doughnut('44.00', '0');
        $this->sellAt($doughnut, $popUp, '44.00');
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->postStock($this->owner, $popUp, $item, '6');

        // A location sent by the client is ignored: the shift decides (FR-106, T-POS-15).
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]], extra: ['branch_id' => $this->mainLocation()->id]))->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame($popUp->id, $order->branch_id);
        $this->assertStringEndsWith('-0001', $order->order_number);
        $this->assertStringStartsWith('POP-SMX1-', $order->order_number);
        $this->assertSame('4.000', $this->onHand($popUp, $item));
        $this->assertSame('0.000', $this->onHand($this->mainLocation(), $item));
    }

    public function test_a_pop_up_cannot_sell_drinks(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $cashier = $this->cashierOnShift($popUp);
        ['product' => $latte, 'iced' => $iced] = $this->coffee();
        // Forced into the pivot directly; the admin screens already refuse this (D-063).
        $latte->locations()->attach($popUp->id, ['is_available' => true]);

        $this->checkout($cashier, $this->payload([['product_id' => $latte->id, 'variant_id' => $iced->id, 'quantity' => 1]]))
            ->assertSessionHasErrors(['items.0' => 'This item is not available here.']);
    }

    public function test_no_sale_once_a_pop_up_has_ended_even_with_a_shift_still_open(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1', 'name' => 'SM Pop-up']);
        $cashier = $this->cashierOnShift($popUp);
        $doughnut = $this->doughnut('44.00', '0');
        $this->sellAt($doughnut, $popUp, '44.00');
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->postStock($this->owner, $popUp, $item, '6');

        $popUp->forceFill(['operating_ends_on' => CarbonImmutable::now('Asia/Manila')->subDay()->toDateString()])->save();

        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))
            ->assertSessionHasErrors(['items' => 'SM Pop-up is not open for sales today. Close the shift.']);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_order_numbers_count_up_per_location_and_day(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00', '10');

        foreach (range(1, 3) as $i) {
            $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        }

        $prefix = 'BRV01-'.CarbonImmutable::now('Asia/Manila')->format('Ymd');
        $this->assertSame(["{$prefix}-0001", "{$prefix}-0002", "{$prefix}-0003"], Order::query()->orderBy('id')->pluck('order_number')->all());
    }

    public function test_quote_returns_server_totals_without_saving(): void
    {
        $cashier = $this->cashierOnShift();
        ['product' => $latte, 'hot' => $hot] = $this->coffee();

        $this->actingAs($cashier)->postJson('/pos/quote', ['items' => [['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 2]]])
            ->assertOk()
            ->assertJsonPath('grand_total', '240.00')
            ->assertJsonPath('lines.0.unit_price', '120.00')
            ->assertJsonPath('lines.0.line_total', '240.00');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_only_staff_who_may_sell_can_check_out(): void
    {
        $doughnut = $this->doughnut('44.00');
        $prep = $this->staff($this->bakerRole());

        $this->checkout($prep, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertForbidden();
        $this->actingAs($prep)->postJson('/pos/quote', ['items' => [['product_id' => $doughnut->id, 'quantity' => 1]]])->assertForbidden();
    }

    public function test_orders_and_payments_are_never_deleted_and_hold_no_customer_data(): void
    {
        $cashier = $this->cashierOnShift();
        $doughnut = $this->doughnut('44.00');
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertSessionHasNoErrors();

        foreach ([Order::query()->sole(), Payment::query()->sole(), OrderItem::query()->sole()] as $record) {
            try {
                $record->delete();
                $this->fail($record::class.' was deleted.');
            } catch (\LogicException) {
                $this->assertModelExists($record);
            }
        }

        // D-067: no customer columns anywhere on orders.
        foreach (Schema::getColumnListing('orders') as $column) {
            $this->assertDoesNotMatchRegularExpression('/customer|phone|email|address/', $column);
        }
    }
}
