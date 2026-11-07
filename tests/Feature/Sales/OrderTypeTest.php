<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderType;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Order types (FR-028, D-181): Dine-in, Takeout and Advance Order; Walk-in and Bulk removed. */
class OrderTypeTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_the_pos_offers_dine_in_takeout_and_advance_order(): void
    {
        $cashier = $this->cashierOnShift();

        $this->actingAs($cashier)->get('/pos')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orderTypes', [
                ['value' => 'dine_in', 'label' => 'Dine-in'],
                ['value' => 'takeout', 'label' => 'Takeout'],
                ['value' => 'advance_order', 'label' => 'Advance Order'],
            ]));
    }

    public function test_checkout_refuses_walk_in_and_bulk(): void
    {
        $product = $this->doughnut();
        $cashier = $this->cashierOnShift();

        foreach (['walk_in', 'bulk', 'advance_pickup'] as $type) {
            $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]], extra: ['order_type' => $type]))
                ->assertSessionHasErrors('order_type');
        }
        $this->assertSame(0, Order::query()->count());

        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]], extra: ['order_type' => 'advance_order']))
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderType::AdvanceOrder, Order::query()->sole()->order_type);

        // The database refuses them too (MySQL CHECK).
        $this->expectException(QueryException::class);
        DB::table('orders')->update(['order_type' => 'walk_in']);
    }
}
