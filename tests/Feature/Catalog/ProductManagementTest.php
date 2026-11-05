<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Enums\InventoryTrackingMode;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductPrice;
use Database\Seeders\CatalogReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** UC-002, FR-016–FR-018, FR-020, BR-014 · T-CAT-1..10 */
class ProductManagementTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->seed(CatalogReferenceSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function doughnut(array $overrides = []): array
    {
        return [
            'name' => 'Classic Glazed',
            'sku' => 'dn-glazed',
            'category_id' => Category::query()->where('slug', 'doughnuts')->value('id'),
            'product_type' => 'finished_good',
            'inventory_tracking_mode' => 'finished_stock',
            'base_price' => '45.00',
            'location_ids' => [$this->mainLocation()->id],
            ...$overrides,
        ];
    }

    public function test_admin_creates_a_doughnut_with_stock_item_price_and_availability(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);

        // After saving, the user goes back to the product list with a success notice.
        $this->actingAs($admin)->post('/admin/products', $this->doughnut())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/products')
            ->assertInertiaFlash('toast.message', 'Classic Glazed created.');

        $product = Product::query()->where('sku', 'DN-GLAZED')->firstOrFail();
        $this->assertSame(InventoryTrackingMode::FinishedStock, $product->inventory_tracking_mode);
        $this->assertSame('DN-GLAZED', InventoryItem::query()->findOrFail($product->inventory_item_id)->sku);
        $this->assertSame('45.00', ProductPrice::query()->where('product_id', $product->id)->sole()->price);
        $this->assertTrue($product->locations()->where('branches.id', $this->mainLocation()->id)->exists());
        $this->assertSame($admin->id, $product->created_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.created', 'auditable_id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.price_set', 'auditable_id' => $product->id]);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        Product::factory()->create(['sku' => 'DN-GLAZED']);

        $this->actingAs($admin)->post('/admin/products', $this->doughnut())->assertSessionHasErrors('sku');
    }

    public function test_invalid_prices_are_rejected_and_nothing_is_saved(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);

        foreach (['-1', 'abc', '12.345', '1e3', ''] as $bad) {
            $this->actingAs($admin)->post('/admin/products', $this->doughnut(['base_price' => $bad]))
                ->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_type_and_tracking_mode_must_be_compatible(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);

        $this->actingAs($admin)->post('/admin/products', $this->doughnut(['inventory_tracking_mode' => 'none']))
            ->assertSessionHasErrors('inventory_tracking_mode');
        $this->actingAs($admin)->post('/admin/products', $this->doughnut(['product_type' => 'made_to_order', 'inventory_tracking_mode' => 'finished_stock']))
            ->assertSessionHasErrors('inventory_tracking_mode');
    }

    public function test_pop_ups_only_sell_finished_stock_items(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-X']);

        $this->actingAs($admin)->post('/admin/products', $this->doughnut([
            'name' => 'Latte', 'sku' => 'CF-LATTE', 'product_type' => 'made_to_order', 'inventory_tracking_mode' => 'none',
            'location_ids' => [$this->mainLocation()->id, $popUp->id],
        ]))->assertSessionHasErrors('location_ids');

        $this->actingAs($admin)->post('/admin/products', $this->doughnut(['location_ids' => [$this->mainLocation()->id, $popUp->id]]))
            ->assertSessionHasNoErrors();
    }

    public function test_variants_keep_one_default_and_cannot_be_removed(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $this->actingAs($admin)->post('/admin/products', $this->doughnut([
            'variants' => [['name' => 'Single', 'is_active' => true], ['name' => 'Box of 6', 'sku' => 'dn-glazed-6', 'is_active' => true]],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'DN-GLAZED')->firstOrFail();
        $variants = $product->variants()->get();
        $this->assertSame(['Single'], $variants->where('is_default', true)->pluck('name')->all());
        $this->assertSame('DN-GLAZED-6', $variants->firstWhere('name', 'Box of 6')?->sku);

        $base = ['name' => 'Classic Glazed', 'sku' => 'DN-GLAZED', 'category_id' => $product->category_id, 'product_type' => 'finished_good', 'inventory_tracking_mode' => 'finished_stock', 'location_ids' => [$this->mainLocation()->id]];
        [$single, $box] = [$variants->firstWhere('name', 'Single'), $variants->firstWhere('name', 'Box of 6')];

        // Removing a saved variant is refused.
        $this->actingAs($admin)->put("/admin/products/{$product->id}", [...$base, 'variants' => [['id' => $single?->id, 'name' => 'Single', 'is_active' => true]]])
            ->assertSessionHasErrors('variants');

        // Two defaults are refused; switching the default works.
        $this->actingAs($admin)->put("/admin/products/{$product->id}", [...$base, 'variants' => [
            ['id' => $single?->id, 'name' => 'Single', 'is_default' => true, 'is_active' => true],
            ['id' => $box?->id, 'name' => 'Box of 6', 'is_default' => true, 'is_active' => true],
        ]])->assertSessionHasErrors('variants');
        $this->actingAs($admin)->put("/admin/products/{$product->id}", [...$base, 'variants' => [
            ['id' => $single?->id, 'name' => 'Single', 'is_default' => false, 'is_active' => false],
            ['id' => $box?->id, 'name' => 'Box of 6', 'sku' => 'DN-GLAZED-6', 'is_default' => true, 'is_active' => true],
        ]])->assertSessionHasNoErrors();

        $this->assertTrue($box?->refresh()->is_default);
        $this->assertFalse($single?->refresh()->is_active);
    }

    public function test_option_groups_and_image_are_saved(): void
    {
        Storage::fake('public');
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $milk = OptionGroup::query()->create(['name' => 'Milk', 'selection_type' => 'single', 'min_selections' => 0, 'max_selections' => 1]);

        $this->actingAs($admin)->post('/admin/products', $this->doughnut([
            'option_group_ids' => [$milk->id],
            'image' => UploadedFile::fake()->image('glazed.jpg', 400, 400),
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'DN-GLAZED')->firstOrFail();
        $this->assertSame([$milk->id], $product->optionGroups()->pluck('option_groups.id')->all());
        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists((string) $product->image_path);

        $this->actingAs($admin)->put("/admin/products/{$product->id}", [
            ...$this->doughnut(['sku' => 'DN-GLAZED']), 'base_price' => null, 'remove_image' => true,
        ])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing((string) $product->image_path);
        $this->assertNull($product->refresh()->image_path);
    }

    public function test_archiving_keeps_history_and_products_cannot_be_deleted(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $this->actingAs($admin)->post('/admin/products', $this->doughnut());
        $product = Product::query()->where('sku', 'DN-GLAZED')->firstOrFail();

        $this->actingAs($admin)->patch("/admin/products/{$product->id}/status", ['is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($product->refresh()->is_active);
        $this->assertSame(1, ProductPrice::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.archived', 'auditable_id' => $product->id]);

        $this->expectException(LogicException::class);
        $product->delete();
    }

    public function test_mark_unavailable_without_archiving(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $product = Product::factory()->create();

        $this->actingAs($admin)->patch("/admin/products/{$product->id}/pos-availability", ['is_available_for_pos' => false])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertTrue($product->is_active);
        $this->assertFalse($product->is_available_for_pos);
        $this->assertSame(1, AuditLog::query()->where('event', 'product.pos_availability_changed')->count());
    }

    public function test_view_only_roles_cannot_change_products(): void
    {
        $manager = $this->staff(PermissionCatalog::MANAGER);
        $product = Product::factory()->create();

        $this->actingAs($manager)->get('/admin/products')->assertOk();
        $this->actingAs($manager)->post('/admin/products', $this->doughnut())->assertForbidden();
        $this->actingAs($manager)->patch("/admin/products/{$product->id}/status", ['is_active' => false])->assertForbidden();
        $this->actingAs($manager)->post("/admin/products/{$product->id}/prices", ['price' => '1.00'])->assertForbidden();
        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_reference_data_is_seeded_idempotently(): void
    {
        $this->seed(CatalogReferenceSeeder::class);

        $this->assertSame(['coffee', 'cold-drinks', 'doughnuts', 'other'], Category::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertSame(0, Product::query()->count());
    }
}
