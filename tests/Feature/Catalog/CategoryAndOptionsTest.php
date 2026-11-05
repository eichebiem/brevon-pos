<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-015, FR-018 · T-CAT-18..23 */
class CategoryAndOptionsTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->admin = $this->staff(PermissionCatalog::ADMIN);
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

    public function test_option_groups_enforce_their_selection_rules(): void
    {
        $base = ['name' => 'Size', 'selection_type' => 'single', 'min_selections' => 1, 'is_active' => true, 'options' => [
            ['name' => 'Regular', 'price_delta' => '0', 'is_active' => true],
            ['name' => 'Large', 'price_delta' => '15.00', 'is_active' => true],
        ]];

        $this->actingAs($this->admin)->post('/admin/option-groups', [...$base, 'min_selections' => 2])->assertSessionHasErrors('min_selections');
        $this->actingAs($this->admin)->post('/admin/option-groups', [...$base, 'options' => [['name' => 'Cheaper', 'price_delta' => '-5', 'is_active' => true]]])
            ->assertSessionHasErrors('options.0.price_delta');
        $this->actingAs($this->admin)->post('/admin/option-groups', [...$base, 'selection_type' => 'multiple', 'min_selections' => 3, 'max_selections' => 2])
            ->assertSessionHasErrors('max_selections');
        $this->actingAs($this->admin)->post('/admin/option-groups', [...$base, 'min_selections' => 1, 'options' => [['name' => 'Only', 'price_delta' => '0', 'is_active' => false]]])
            ->assertSessionHasErrors('min_selections');

        $this->actingAs($this->admin)->post('/admin/option-groups', [...$base, 'max_selections' => 5])->assertSessionHasNoErrors();

        $group = OptionGroup::query()->where('name', 'Size')->firstOrFail();
        $this->assertSame(1, $group->max_selections);
        $this->assertSame(['0.00', '15.00'], $group->options()->pluck('price_delta')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'option_group.created', 'auditable_id' => $group->id]);
    }

    public function test_options_are_deactivated_not_deleted(): void
    {
        $group = OptionGroup::query()->create(['name' => 'Milk', 'selection_type' => 'single', 'min_selections' => 0, 'max_selections' => 1]);
        $oat = $group->options()->create(['name' => 'Oat', 'price_delta' => '20.00']);

        $this->actingAs($this->admin)->put("/admin/option-groups/{$group->id}", [
            'name' => 'Milk', 'selection_type' => 'single', 'min_selections' => 0, 'is_active' => true,
            'options' => [['id' => $oat->id, 'name' => 'Oat', 'price_delta' => '25', 'is_active' => false], ['name' => 'Soy', 'price_delta' => '20', 'is_active' => true]],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($oat->refresh()->is_active);
        $this->assertSame('25.00', $oat->price_delta);
        $this->assertSame(2, $group->options()->count());
    }
}
