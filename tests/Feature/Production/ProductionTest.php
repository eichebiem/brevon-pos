<?php

namespace Tests\Feature\Production;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryItemType;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Enums\ProductionBatchStatus;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 8: recipes and production (FR-064–FR-072; T-PRD-B1–B6; D-140–D-144). */
class ProductionTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $manager;

    private User $prep;

    private Branch $main;

    private Product $doughnut;

    private InventoryItem $flour;

    private InventoryItem $egg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->main = $this->mainLocation();
        $this->manager = $this->staff(PermissionCatalog::MANAGER);
        $this->prep = $this->staff(PermissionCatalog::PREPARATION_STAFF);

        $this->doughnut = Product::factory()->finishedStock()->create(['name' => 'Choco Custard']);
        $this->flour = $this->ingredientItem('ING-FLOUR');
        $pc = UnitOfMeasure::query()->firstOrCreate(['code' => 'pc'], ['name' => 'Piece', 'precision' => 0]);
        $this->egg = InventoryItem::query()->create(['sku' => 'ING-EGG', 'name' => 'Egg', 'item_type' => InventoryItemType::Ingredient, 'unit_of_measure_id' => $pc->id]);
    }

    private function stock(InventoryItem $item, string $quantity, ?string $cost = null, ?Branch $at = null): void
    {
        app(InventoryLedger::class)->post($this->owner, [new StockChange(($at ?? $this->main)->id, $item->id, InventoryMovementType::Opening, Quantity::fromString($quantity), $cost)]);
    }

    /**
     * @param  list<array{0: InventoryItem, 1: string, 2?: string}>  $lines  item, quantity, wastage %
     * @param  array<string, mixed>  $extra
     */
    private function saveRecipe(Product $product, array $lines, ?string $makes = '12', array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->post("/admin/recipes/{$product->id}", [
            'output_quantity' => $makes,
            'lines' => array_map(fn (array $l) => ['inventory_item_id' => $l[0]->id, 'quantity' => $l[1], 'wastage_percent' => $l[2] ?? ''], $lines),
            ...$extra,
        ]);
    }

    /** A batch of 12 uses 1000 g flour (5% wastage) and 4 eggs. */
    private function doughnutRecipe(): void
    {
        $this->saveRecipe($this->doughnut, [[$this->flour, '1000', '5'], [$this->egg, '4']])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function batch(array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->post('/admin/production', [
            'product_id' => $this->doughnut->id,
            'planned' => '12',
            'actual' => '10',
            'rejected' => '2',
            'reject_reason' => 'Burnt',
            ...$data,
        ]);
    }

    private function doughnutItem12(): InventoryItem
    {
        /** @var InventoryItem */
        return $this->doughnut->inventoryItem;
    }

    public function test_posting_a_batch_uses_ingredients_with_wastage_and_adds_good_pieces_in_one_go(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000', '0.0500');
        $this->stock($this->egg, '30', '9.0000');

        $this->batch(['post' => true])->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();

        // 12 pieces made (10 good + 2 rejected): flour 1000 × 1.05 = 1050 g, eggs 4.
        $this->assertSame(ProductionBatchStatus::Posted, $batch->status);
        $this->assertSame('BRV01-'.now('Asia/Manila')->format('Ymd').'-PB01', $batch->batch_number);
        $this->assertSame('3950.000', $this->onHand($this->main, $this->flour));
        $this->assertSame('26.000', $this->onHand($this->main, $this->egg));
        $this->assertSame('10.000', $this->onHand($this->main, $this->doughnutItem12()));

        $moves = InventoryMovement::query()->where('reference_type', 'production_batch')->where('reference_id', $batch->id)->get();
        $this->assertCount(3, $moves);
        $this->assertSame(2, $moves->where('movement_type', InventoryMovementType::ProductionConsumption)->count());
        $this->assertSame(1, $moves->where('movement_type', InventoryMovementType::ProductionOutput)->count());

        // (1050 × 0.05 + 4 × 9) ÷ 10 good pieces = 8.85 each; rejects are carried by the good ones.
        $this->assertSame('8.8500', $batch->unit_cost);
        $this->assertSame(['0.0500', '9.0000'], $batch->items()->orderBy('inventory_item_id')->pluck('unit_cost_snapshot')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'production_batch.posted', 'auditable_id' => $batch->id]);
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    public function test_ingredient_use_can_be_changed_and_an_unknown_cost_leaves_the_piece_cost_unknown(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000');
        $this->stock($this->egg, '30', '9.0000');

        $this->batch(['actual' => '12', 'rejected' => '', 'usage' => [$this->egg->id => '5', $this->flour->id => '']])->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();
        $this->assertSame(ProductionBatchStatus::Draft, $batch->status);
        $this->assertSame(['1050.000', '5.000'], $batch->items()->orderBy('inventory_item_id')->pluck('quantity_consumed')->all());
        $this->assertSame('5000.000', $this->onHand($this->main, $this->flour), 'a draft moves no stock');

        $this->batch(['usage' => [$this->egg->id => '1.5']])->assertSessionHasErrors("usage.{$this->egg->id}");

        $this->actingAs($this->manager)->post("/admin/production/{$batch->id}/post")->assertSessionHasNoErrors();
        $this->assertNull($batch->refresh()->unit_cost, 'flour has no cost yet');
        $this->assertSame('25.000', $this->onHand($this->main, $this->egg));
    }

    public function test_not_enough_of_an_ingredient_posts_nothing(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '500');
        $this->stock($this->egg, '30');

        $this->batch(['post' => true])->assertSessionHasErrors('batch');
        $this->assertSame(0, ProductionBatch::query()->count(), 'save and post is all or nothing');

        $this->batch()->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();
        $this->actingAs($this->manager)->post("/admin/production/{$batch->id}/post")->assertSessionHasErrors('batch');

        $this->assertSame(ProductionBatchStatus::Draft, $batch->refresh()->status);
        $this->assertSame('500.000', $this->onHand($this->main, $this->flour));
        $this->assertSame('30.000', $this->onHand($this->main, $this->egg));
        $this->assertSame('0.000', $this->onHand($this->main, $this->doughnutItem12()));
        $this->assertNull($batch->items()->first()?->unit_cost_snapshot);
        $this->assertSame(0, InventoryMovement::query()->where('reference_type', 'production_batch')->count());
    }

    public function test_a_batch_posts_once_and_only_drafts_can_change(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000');
        $this->stock($this->egg, '30');
        $this->batch(['post' => true])->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();

        $this->actingAs($this->manager)->post("/admin/production/{$batch->id}/post")->assertSessionHasErrors();
        $this->actingAs($this->manager)->put("/admin/production/{$batch->id}", ['product_id' => $this->doughnut->id, 'planned' => '12', 'actual' => '12'])->assertSessionHasErrors();
        $this->actingAs($this->manager)->post("/admin/production/{$batch->id}/cancel", ['reason' => 'Wrong batch'])->assertSessionHasErrors();
        $this->assertSame('10.000', $this->onHand($this->main, $this->doughnutItem12()));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::ProductionOutput)->count());

        $this->expectException(LogicException::class);
        $batch->forceFill(['actual_quantity' => '50'])->save();
    }

    public function test_a_draft_can_be_edited_or_cancelled_with_a_reason(): void
    {
        $this->doughnutRecipe();
        $this->batch(['actual' => '11', 'rejected' => '1'])->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();

        $this->actingAs($this->prep)->put("/admin/production/{$batch->id}", ['product_id' => $this->doughnut->id, 'planned' => '24', 'actual' => '24'])->assertSessionHasNoErrors();
        $batch->refresh();
        $this->assertSame(['24.000', '0.000', null], [$batch->actual_quantity, $batch->rejected_quantity, $batch->reject_reason]);
        $this->assertSame(['2100.000', '8.000'], $batch->items()->orderBy('inventory_item_id')->pluck('quantity_consumed')->all());

        $this->actingAs($this->prep)->post("/admin/production/{$batch->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->prep)->post("/admin/production/{$batch->id}/cancel", ['reason' => 'Oven broke'])->assertSessionHasNoErrors();
        $this->assertSame(ProductionBatchStatus::Cancelled, $batch->refresh()->status);
        $this->assertSame('Oven broke', $batch->cancel_reason);
        $this->assertSame(0, InventoryMovement::query()->where('reference_type', 'production_batch')->count());
    }

    public function test_rejects_are_recorded_with_a_reason_and_never_stocked(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000');
        $this->stock($this->egg, '30');

        $this->batch(['reject_reason' => ''])->assertSessionHasErrors('reject_reason');
        $this->batch(['actual' => '0', 'rejected' => ''])->assertSessionHasErrors('actual');
        $this->batch(['actual' => '1.5'])->assertSessionHasErrors('actual');
        $this->batch(['planned' => '0'])->assertSessionHasErrors('planned');

        // A whole batch can be rejected: ingredients are used, nothing is added.
        $this->batch(['actual' => '0', 'rejected' => '12', 'reject_reason' => 'Dough did not rise', 'post' => true])->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();
        $this->assertSame(['0.000', '12.000', 'Dough did not rise'], [$batch->actual_quantity, $batch->rejected_quantity, $batch->reject_reason]);
        $this->assertSame('3950.000', $this->onHand($this->main, $this->flour));
        $this->assertSame('0.000', $this->onHand($this->main, $this->doughnutItem12()));
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::ProductionOutput)->count());
        $this->assertNull($batch->unit_cost);
    }

    public function test_saving_a_recipe_makes_a_new_version_and_batches_keep_theirs(): void
    {
        $this->doughnutRecipe();
        $this->batch()->assertSessionHasNoErrors();
        $first = Recipe::query()->sole();

        $this->saveRecipe($this->doughnut, [[$this->flour, '900']], '10')->assertSessionHasNoErrors()->assertRedirect("/admin/recipes/{$this->doughnut->id}");
        $versions = Recipe::query()->where('product_id', $this->doughnut->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame([false, true], $versions->pluck('is_active')->all());
        $this->assertNotNull($versions[0]->effective_until);
        $this->assertSame($first->id, ProductionBatch::query()->sole()->recipe_id);
        $this->assertSame(2, $first->items()->count(), 'the old version keeps its lines');
        $this->assertDatabaseHas('audit_logs', ['event' => 'recipe.saved', 'auditable_id' => $versions[1]->id]);

        $this->expectException(LogicException::class);
        $first->delete();
    }

    public function test_recipe_lines_are_checked(): void
    {
        $this->saveRecipe($this->doughnut, [])->assertSessionHasErrors('lines');
        $this->saveRecipe($this->doughnut, [[$this->flour, '']])->assertSessionHasErrors('lines');
        $this->saveRecipe($this->doughnut, [[$this->flour, '100']], '1.5')->assertSessionHasErrors('output_quantity');
        $this->saveRecipe($this->doughnut, [[$this->flour, '-1']])->assertSessionHasErrors('lines.0.quantity');
        $this->saveRecipe($this->doughnut, [[$this->flour, '100', '101']])->assertSessionHasErrors('lines.0.wastage_percent');
        $this->saveRecipe($this->doughnut, [[$this->flour, '100'], [$this->flour, '50']])->assertSessionHasErrors('lines.1.quantity');
        $this->saveRecipe($this->doughnut, [[$this->doughnutItem12(), '1']])->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(0, Recipe::query()->count());

        // Products that do not use stock cannot have a recipe.
        $plain = Product::factory()->create();
        $this->saveRecipe($plain, [[$this->flour, '100']])->assertNotFound();
    }

    public function test_a_doughnut_sale_only_takes_the_finished_doughnut(): void
    {
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000');
        $this->stock($this->egg, '30');
        $this->batch(['post' => true])->assertSessionHasNoErrors();
        $this->sellAt($this->doughnut, $this->main, '44.00');

        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([['product_id' => $this->doughnut->id, 'quantity' => 3]]))->assertSessionHasNoErrors();

        $this->assertSame('7.000', $this->onHand($this->main, $this->doughnutItem12()));
        $this->assertSame('3950.000', $this->onHand($this->main, $this->flour), 'FR-071: no second ingredient deduction');
        $this->assertSame('26.000', $this->onHand($this->main, $this->egg));
    }

    public function test_a_drink_takes_its_ingredients_at_checkout_and_gives_them_back_on_cancel(): void
    {
        ['product' => $latte, 'iced' => $iced, 'hot' => $hot] = $this->coffee();
        $latte->forceFill(['inventory_tracking_mode' => InventoryTrackingMode::RecipeAtSale])->save();
        $ml = UnitOfMeasure::query()->firstOrCreate(['code' => 'ml'], ['name' => 'Millilitre', 'precision' => 0]);
        $milk = InventoryItem::query()->create(['sku' => 'ING-MILK', 'name' => 'Milk', 'item_type' => InventoryItemType::Ingredient, 'unit_of_measure_id' => $ml->id]);
        $beans = $this->ingredientItem('ING-BEANS');
        $this->stock($milk, '1000');
        $this->stock($beans, '500');

        // Every size: 18 g beans and 200 ml milk. Iced has its own: 18 g beans, 250 ml milk.
        $this->saveRecipe($latte, [[$beans, '18'], [$milk, '200']], null)->assertSessionHasNoErrors();
        $this->saveRecipe($latte, [[$beans, '18'], [$milk, '250']], null, ['size_id' => $iced->id])->assertSessionHasNoErrors();
        $this->assertSame(['1.000', '1.000'], Recipe::query()->pluck('output_quantity')->all(), 'drink recipes are per serving');

        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([
            ['product_id' => $latte->id, 'variant_id' => $iced->id, 'quantity' => 2],
            ['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 1],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('300.000', $this->onHand($this->main, $milk), '2 × 250 + 1 × 200');
        $this->assertSame('446.000', $this->onHand($this->main, $beans), '3 × 18');

        // Not enough milk refuses the whole sale.
        $this->checkout($cashier, $this->payload([['product_id' => $latte->id, 'variant_id' => $iced->id, 'quantity' => 2]]))->assertSessionHasErrors();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('300.000', $this->onHand($this->main, $milk));

        $order = Order::query()->sole();
        $this->actingAs($this->manager)->post("/orders/{$order->id}/cancel", ['reason' => 'Wrong order', 'return_stock' => true])->assertSessionHasNoErrors();
        $this->assertSame('1000.000', $this->onHand($this->main, $milk));
        $this->assertSame('500.000', $this->onHand($this->main, $beans));
        $this->assertTrue(app(InventoryLedger::class)->reconcile()->isEmpty());
    }

    public function test_a_drink_without_a_recipe_takes_nothing(): void
    {
        ['product' => $latte, 'hot' => $hot] = $this->coffee();
        $latte->forceFill(['inventory_tracking_mode' => InventoryTrackingMode::RecipeAtSale])->save();

        $this->checkout($this->cashierOnShift(), $this->payload([['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::Sale)->count());
    }

    public function test_preparation_staff_can_draft_but_only_a_manager_posts_and_only_owners_edit_recipes(): void
    {
        $this->saveRecipe($this->doughnut, [[$this->flour, '1000']], '12', [], $this->manager)->assertForbidden();
        $this->saveRecipe($this->doughnut, [[$this->flour, '1000']], '12', [], $this->prep)->assertForbidden();
        $this->doughnutRecipe();
        $this->stock($this->flour, '5000');
        $this->stock($this->egg, '30');

        $this->actingAs($this->prep)->get('/admin/production/create')->assertOk();
        $this->batch(['post' => true], $this->prep)->assertSessionHasErrors('batch');
        $this->assertSame(0, ProductionBatch::query()->count());
        $this->batch([], $this->prep)->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();
        $this->actingAs($this->prep)->post("/admin/production/{$batch->id}/post")->assertForbidden();

        $this->actingAs($this->manager)->post("/admin/production/{$batch->id}/post")->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, $batch->refresh()->posted_by);
        $this->assertSame($this->prep->id, $batch->created_by);

        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $this->actingAs($cashier)->get("/admin/production/{$batch->id}")->assertForbidden();
    }

    public function test_production_happens_at_the_main_branch_only(): void
    {
        $this->doughnutRecipe();
        $this->batch()->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();

        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $stall = $this->staff(PermissionCatalog::MANAGER, $popUp);
        $this->actingAs($stall)->get('/admin/production/create')->assertNotFound();
        $this->actingAs($stall)->get("/admin/production/{$batch->id}")->assertNotFound();
        $this->actingAs($stall)->post("/admin/production/{$batch->id}/post")->assertNotFound();
        $this->batch([], $stall)->assertNotFound();
        $this->assertSame(1, ProductionBatch::query()->count());
    }

    public function test_the_pages_render(): void
    {
        $this->doughnutRecipe();
        $this->batch()->assertSessionHasNoErrors();
        $batch = ProductionBatch::query()->sole();

        $this->actingAs($this->owner)->get('/admin/recipes')->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/recipes/Index'));
        $this->actingAs($this->owner)->get("/admin/recipes/{$this->doughnut->id}")->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/recipes/Show'));
        $this->actingAs($this->owner)->get("/admin/recipes/{$this->doughnut->id}/new-version")->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/recipes/Form'));
        $this->actingAs($this->manager)->get('/admin/production')->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/batches/Index')->where('draftCount', 1));
        $this->actingAs($this->manager)->get("/admin/production/{$batch->id}")->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/batches/Show')->where('can.post', true));
        $this->actingAs($this->manager)->get("/admin/production/{$batch->id}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('admin/production/batches/Form')->has('products', 1));
    }
}
