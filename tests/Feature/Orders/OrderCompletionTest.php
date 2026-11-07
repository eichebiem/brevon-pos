<?php

namespace Tests\Feature\Orders;

use App\Domain\Access\PermissionCatalog;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 11a: preparation and the Cancel action are removed (D-169). */
class OrderCompletionTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $cashier;

    private User $manager;

    /** @var array{product: Product, iced: ProductVariant, hot: ProductVariant} */
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
    }

    /**
     * A made-to-order latte plus two doughnuts.
     *
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $extra = []): Order
    {
        $this->checkout($this->cashier, $this->payload([
            ['product_id' => $this->latte['product']->id, 'variant_id' => $this->latte['iced']->id, 'quantity' => 1, 'notes' => 'less ice'],
            ['product_id' => $this->doughnut->id, 'quantity' => 2],
        ], ['method' => 'CASH', 'amount_tendered' => '500'], $extra))->assertSessionHasNoErrors()->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_every_sale_is_completed_at_checkout_even_made_to_order_and_advance_pickup(): void
    {
        foreach (['walk_in', 'advance_pickup'] as $type) {
            $order = $this->sell(['order_type' => $type, 'pickup_at' => '2030-01-01 10:00']);

            $this->assertSame(OrderStatus::Completed, $order->status);
            $this->assertNotNull($order->completed_at);
            $history = OrderStatusHistory::query()->where('order_id', $order->id)->get();
            $this->assertCount(1, $history);
            $this->assertNull($history[0]->from_status);
            $this->assertSame(OrderStatus::Completed, $history[0]->to_status);
        }

        $this->assertFalse(Schema::hasColumn('orders', 'pickup_at'));
    }

    public function test_the_preparation_screen_status_steps_and_cancel_are_gone(): void
    {
        $order = $this->sell();

        $this->actingAs($this->manager)->get('/staff/preparation')->assertNotFound();
        $this->actingAs($this->manager)->post("/orders/{$order->id}/advance", ['from' => 'confirmed'])->assertNotFound();
        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Test', 'return_stock' => true])->assertNotFound();

        $this->assertFalse(Permission::query()->whereIn('name', ['orders.update-status', 'orders.cancel'])->exists());
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_the_order_page_offers_a_refund_as_the_only_correction(): void
    {
        $order = $this->sell();

        $this->actingAs($this->manager)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.status', 'completed')
                ->missing('workflow.next_status')
                ->missing('workflow.can_advance')
                ->missing('workflow.can_cancel')
                ->where('workflow.refund.refunded', '0.00')
                ->has('workflow.history', 1));
    }

    public function test_a_baker_role_lands_on_production(): void
    {
        $baker = $this->staff($this->bakerRole(), $this->mainLocation());

        $this->actingAs($baker)->get('/home')->assertRedirect('/admin/production');
        $this->actingAs($baker)->get('/admin/production')->assertOk();
    }

    public function test_a_past_cancelled_order_still_reads_and_cannot_be_refunded(): void
    {
        $order = $this->sell();
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $this->manager->id, 'cancellation_reason' => 'Before D-169'])->save();

        $this->actingAs($this->manager)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.status', 'cancelled')
                ->where('workflow.cancellation.reason', 'Before D-169'));
    }
}
