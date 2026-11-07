<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-015, FR-018 · T-CAT-18..23 */
class CategoryTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->admin = $this->staff(PermissionCatalog::OWNER);
    }

    public function test_categories_get_unique_slugs_and_are_audited(): void
    {
        $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Specials'])->assertSessionHasNoErrors();
        $first = Category::query()->where('name', 'Specials')->firstOrFail();
        $this->actingAs($this->admin)->put("/admin/categories/{$first->id}", ['name' => 'Old specials'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Specials'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Specials!'])->assertSessionHasNoErrors();

        $this->assertSame(['old-specials', 'specials', 'specials-2'], Category::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'category.updated', 'auditable_id' => $first->id]);
    }

    public function test_category_list_follows_sort_order_then_name_with_children_under_their_parent(): void
    {
        $zebra = Category::factory()->create(['name' => 'Zebra', 'sort_order' => 1]);
        Category::factory()->create(['name' => 'Apple', 'sort_order' => 99]);
        Category::factory()->create(['name' => 'Zest', 'parent_id' => $zebra->id, 'sort_order' => 1]);
        Category::factory()->create(['name' => 'Berry', 'parent_id' => $zebra->id, 'sort_order' => 1]);

        $this->actingAs($this->admin)->get('/admin/categories')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/categories/Index')
                ->where('categories', fn ($rows) => collect($rows)->pluck('name')->all() === ['Zebra', 'Berry', 'Zest', 'Apple'])
                ->where('categories.0.sort_order', 1));
    }

    public function test_categories_nest_only_one_level(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Grandchild', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->actingAs($this->admin)->put("/admin/categories/{$parent->id}", ['name' => $parent->name, 'parent_id' => $parent->id])->assertSessionHasErrors('parent_id');
        $other = Category::factory()->create();
        $this->actingAs($this->admin)->put("/admin/categories/{$parent->id}", ['name' => $parent->name, 'parent_id' => $other->id])->assertSessionHasErrors('parent_id');
    }

    public function test_archiving_rules(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = Product::factory()->create(['category_id' => $child->id]);

        $this->actingAs($this->admin)->patch("/admin/categories/{$child->id}/status", ['is_active' => false])->assertSessionHasErrors('category');

        $product->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->patch("/admin/categories/{$parent->id}/status", ['is_active' => false])->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->patch("/admin/categories/{$child->id}/status", ['is_active' => false])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch("/admin/categories/{$parent->id}/status", ['is_active' => false])->assertSessionHasNoErrors();

        // A child cannot come back before its parent; neither can its product.
        $this->actingAs($this->admin)->patch("/admin/categories/{$child->id}/status", ['is_active' => true])->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->patch("/admin/products/{$product->id}/status", ['is_active' => true])->assertSessionHasErrors('product');
        $this->assertDatabaseHas('audit_logs', ['event' => 'category.archived', 'auditable_id' => $parent->id]);
    }

    public function test_the_options_and_add_ons_screen_is_gone(): void
    {
        // D-166: options & add-ons removed at the client's request; sizes (variants) stay.
        $this->actingAs($this->admin)->get('/admin/option-groups')->assertNotFound();
        $this->assertFalse(Schema::hasTable('option_groups'));
        $this->assertFalse(Schema::hasTable('order_item_options'));
        $this->assertFalse(Schema::hasColumn('order_items', 'option_total'));
    }
}
