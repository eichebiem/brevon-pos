<?php

namespace Tests\Feature\Orders;

use App\Domain\Access\PermissionCatalog;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** D-174: the one-off clean-up before the Phase 11a migrations. */
class FinishOpenOrdersCommandTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $cashier;

    private User $manager;

    private Product $doughnut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->doughnut = $this->doughnut('44.00', '20');
        $this->cashier = $this->cashierOnShift();
        $this->manager = $this->staff(PermissionCatalog::MANAGER, $this->mainLocation(), ['username' => 'mgr']);
    }

    /**
     * A sale put back into a state the old screens left behind.
     *
     * @param  array<string, mixed>  $payment
     */
    private function oldOrder(string $status, string $orderPayment, array $payment = ['method' => 'CASH', 'amount_tendered' => '100'], ?string $paymentStatus = null): Order
    {
        $this->checkout($this->cashier, $this->payload([['product_id' => $this->doughnut->id, 'quantity' => 1]], $payment))->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();
        $order->forceFill(['status' => $status, 'payment_status' => $orderPayment, 'completed_at' => null])->save();
        if ($paymentStatus !== null) {
            Payment::query()->where('order_id', $order->id)->update(['status' => $paymentStatus, 'verified_by' => null, 'verified_at' => null]);
        }

        return $order;
    }

    public function test_without_force_it_only_lists(): void
    {
        $order = $this->oldOrder('confirmed', 'paid');

        $this->artisan('brevon:finish-open-orders')->expectsOutputToContain('Nothing changed')->assertSuccessful();

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_it_marks_waiting_gcash_received_and_completes_paid_orders_with_an_audit_trail(): void
    {
        $cash = $this->oldOrder('preparing', 'paid');
        $gcash = $this->oldOrder('confirmed', 'pending_verification', ['method' => 'GCASH', 'reference' => 'GC99990001'], 'pending');
        $done = $this->oldOrder('completed', 'paid');

        $this->artisan('brevon:finish-open-orders', ['--user' => 'mgr', '--force' => true])
            ->expectsOutputToContain('1 GCash payment(s) marked received, 2 order(s) completed.')
            ->assertSuccessful();

        foreach ([$cash, $gcash] as $order) {
            $order->refresh();
            $this->assertSame([OrderStatus::Completed, OrderPaymentStatus::Paid], [$order->status, $order->payment_status]);
            $this->assertNotNull($order->completed_at);
            $last = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
            $this->assertSame([OrderStatus::Completed, $this->manager->id], [$last->to_status, $last->changed_by]);
        }
        $payment = Payment::query()->where('order_id', $gcash->id)->sole();
        $this->assertSame([PaymentStatus::Verified, $this->manager->id], [$payment->status, $payment->verified_by]);
        $this->assertSame(1, AuditLog::query()->where('event', 'payment.verified')->where('user_id', $this->manager->id)->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'order.completed')->where('user_id', $this->manager->id)->count());
        $this->assertSame(1, OrderStatusHistory::query()->where('order_id', $done->id)->count(), 'finished orders are left alone');

        $this->artisan('brevon:finish-open-orders')->expectsOutputToContain('Nothing to finish')->assertSuccessful();
    }

    public function test_it_needs_an_owner_or_manager_and_leaves_unpaid_orders(): void
    {
        $order = $this->oldOrder('confirmed', 'paid');

        $this->artisan('brevon:finish-open-orders', ['--force' => true])->assertFailed();
        $this->artisan('brevon:finish-open-orders', ['--user' => $this->cashier->username, '--force' => true])->assertFailed();
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        $unpaid = $this->oldOrder('pending', 'unpaid');
        $this->artisan('brevon:finish-open-orders', ['--user' => 'mgr', '--force' => true])
            ->expectsOutputToContain('1 unpaid order(s) were left')
            ->assertFailed();
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $unpaid->refresh()->status);
    }
}
