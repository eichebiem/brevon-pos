<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\OpeningStock;
use App\Domain\Locations\LocationContext;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithStaff;
use Tests\Concerns\InteractsWithStock;
use Tests\TestCase;

/** FR-054, FR-055; D-051, D-063, D-093 · opening stock and the stock screens */
class OpeningStockTest extends TestCase
{
    use InteractsWithStaff;
    use InteractsWithStock;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->manager = $this->staff(PermissionCatalog::MANAGER);
    }

    public function test_manager_enters_opening_stock_once(): void
    {
        $main = $this->mainLocation();
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();

        $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '36', 'unit_cost' => '12.50'],
            ['inventory_item_id' => $flour->id, 'quantity' => '2500.5', 'unit_cost' => ''],
        ]])->assertSessionHasNoErrors()->assertRedirect('/admin/inventory/stock');

        $this->assertSame('36.000', $this->onHand($main, $glazed));
        $this->assertSame('2500.500', $this->onHand($main, $flour));
        $this->assertSame(2, InventoryMovement::query()->where('movement_type', InventoryMovementType::Opening)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.opening_stock', 'auditable_id' => $main->id, 'user_id' => $this->manager->id]);

        // A second opening count for the same item is refused; corrections are adjustments.
        $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '40', 'unit_cost' => null],
        ]])->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame('36.000', $this->onHand($main, $glazed));
    }

    public function test_blank_lines_are_skipped_and_quantities_are_validated(): void
    {
        $glazed = $this->doughnutItem();
        $other = $this->doughnutItem();

        $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '', 'unit_cost' => ''],
        ]])->assertSessionHasErrors('lines');

        // Pieces are whole numbers; negative, zero and text are refused.
        foreach (['1.5', '-3', '0', 'ten'] as $bad) {
            $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
                ['inventory_item_id' => $glazed->id, 'quantity' => $bad, 'unit_cost' => ''],
            ]])->assertSessionHasErrors('lines.0.quantity');
        }

        $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '5', 'unit_cost' => '-1'],
        ]])->assertSessionHasErrors('lines.0.unit_cost');

        // One bad line means nothing is saved.
        $this->actingAs($this->manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '5', 'unit_cost' => ''],
            ['inventory_item_id' => $other->id, 'quantity' => 'x', 'unit_cost' => ''],
        ]])->assertSessionHasErrors('lines.1.quantity');
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_pop_up_opening_stock_is_limited_to_finished_doughnuts(): void
    {
        $popUp = Branch::factory()->popUp()->create();
        $manager = $this->staff(PermissionCatalog::MANAGER, $popUp);
        $glazed = $this->doughnutItem();
        $flour = $this->ingredientItem();

        $this->actingAs($manager)->get('/admin/inventory/opening-stock')
            ->assertInertia(fn ($page) => $page->component('admin/inventory/OpeningStock')
                ->where('isPopUp', true)
                ->has('items', 1)
                ->where('items.0.id', $glazed->id));

        $this->actingAs($manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $flour->id, 'quantity' => '100', 'unit_cost' => ''],
        ]])->assertSessionHasErrors('lines.0.quantity');

        $this->actingAs($manager)->post('/admin/inventory/opening-stock', ['lines' => [
            ['inventory_item_id' => $glazed->id, 'quantity' => '24', 'unit_cost' => ''],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('24.000', $this->onHand($popUp, $glazed));
        $this->assertSame('0.000', $this->onHand($this->mainLocation(), $glazed));
    }

    public function test_opening_stock_is_refused_at_a_pop_up_that_cannot_operate_today(): void
    {
        $popUp = Branch::factory()->popUp()->create();
        $manager = $this->staff(PermissionCatalog::MANAGER, $popUp);
        $glazed = $this->doughnutItem();

        // The pop-up closes after the manager selected it.
        $popUp->forceFill(['is_active' => false])->save();

        $this->expectException(ValidationException::class);
        app(OpeningStock::class)->post($manager, $popUp, [
            ['inventory_item_id' => $glazed->id, 'quantity' => '5', 'unit_cost' => null],
        ]);
    }

    public function test_stock_and_movement_screens_show_only_the_selected_location(): void
    {
        $main = $this->mainLocation();
        $popUp = Branch::factory()->popUp()->create();
        $this->manager->branches()->attach($popUp->id, ['is_default' => false]);
        $glazed = $this->doughnutItem();
        $this->postStock($this->manager, $main, $glazed, '30');
        $this->postStock($this->manager, $popUp, $glazed, '12');

        $this->actingAs($this->manager)->get('/admin/inventory/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/inventory/Stock')
                ->where('site.code', 'BRV01')
                ->has('rows', 1)
                ->where('rows.0.on_hand', '30.000'));

        $this->actingAs($this->manager)->withSession([LocationContext::SESSION_KEY => $popUp->id])
            ->get('/admin/inventory/movements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/inventory/Movements')
                ->has('movements.data', 1)
                ->where('movements.data.0.quantity', '12.000')
                ->where('movements.data.0.type', 'opening'));
    }

    public function test_cashier_and_preparation_staff_cannot_enter_opening_stock(): void
    {
        $glazed = $this->doughnutItem();

        foreach ([PermissionCatalog::CASHIER, PermissionCatalog::PREPARATION_STAFF] as $role) {
            $this->actingAs($this->staff($role))->post('/admin/inventory/opening-stock', ['lines' => [
                ['inventory_item_id' => $glazed->id, 'quantity' => '5', 'unit_cost' => ''],
            ]])->assertForbidden();
        }

        $this->assertSame(0, InventoryMovement::query()->count());
    }
}
