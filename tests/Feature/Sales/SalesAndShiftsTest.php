<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** How sales affect shifts and locations (FR-077, D-042, D-062). */
class SalesAndShiftsTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_closing_a_shift_expects_the_float_plus_cash_sales_only(): void
    {
        $cashier = $this->cashierOnShift(openingCash: '1000.00');
        $doughnut = $this->doughnut('44.00', '10');
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 2]], ['method' => 'CASH', 'amount_tendered' => '500']))->assertSessionHasNoErrors();
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]], ['method' => 'GCASH', 'reference' => 'REF12345']))->assertSessionHasNoErrors();
        $shift = $this->shiftOf($cashier);

        $this->actingAs($cashier)->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1090.00', 'closing_notes' => 'Found an extra 2.00'])->assertSessionHasNoErrors();

        $shift->refresh();
        // 1000 float + 88 cash sale; the 412 change and the 44 GCash sale are not drawer cash.
        $this->assertSame('1088.00', $shift->expected_cash);
        $this->assertSame('2.00', $shift->variance);

        // A closed shift takes no more sales.
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertSessionHasErrors('shift');
    }

    public function test_pop_up_dates_cannot_be_changed_to_leave_out_days_with_sales(): void
    {
        $today = CarbonImmutable::now('Asia/Manila');
        $popUp = Branch::factory()->popUp($today->subDays(3)->toDateString(), $today->addDays(3)->toDateString())->create(['code' => 'POP-SMX1', 'name' => 'SM Pop-up']);
        $cashier = $this->cashierOnShift($popUp);
        $doughnut = $this->doughnut('44.00', '0');
        $this->sellAt($doughnut, $popUp, '44.00');
        /** @var InventoryItem $item */
        $item = $doughnut->inventoryItem;
        $this->postStock($this->owner, $popUp, $item, '5');
        $this->checkout($cashier, $this->payload([['product_id' => $doughnut->id, 'quantity' => 1]]))->assertSessionHasNoErrors();

        $form = fn (string $start, string $end) => ['name' => 'SM Pop-up', 'operating_starts_on' => $start, 'operating_ends_on' => $end];

        $this->actingAs($this->owner)->put("/admin/locations/{$popUp->id}", $form($today->subDays(3)->toDateString(), $today->subDay()->toDateString()))
            ->assertSessionHasErrors('operating_ends_on');
        $this->actingAs($this->owner)->put("/admin/locations/{$popUp->id}", $form($today->addDay()->toDateString(), $today->addDays(3)->toDateString()))
            ->assertSessionHasErrors('operating_starts_on');

        // Extending, or ending today, is fine.
        $this->actingAs($this->owner)->put("/admin/locations/{$popUp->id}", $form($today->subDays(3)->toDateString(), $today->toDateString()))
            ->assertSessionHasNoErrors();
    }
}
