<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\PriceResolver;
use App\Enums\InventoryTrackingMode;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** D-101: the shop's menu boards as seed data. */
class MenuSeederTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->seed(CatalogReferenceSeeder::class);
    }

    public function test_seeds_the_whole_menu_with_board_prices(): void
    {
        $this->staff(PermissionCatalog::OWNER);

        $this->seed(MenuSeeder::class);

        $this->assertSame(36, Product::query()->count());
        $this->assertSame(6, Product::query()->whereHas('category', fn ($q) => $q->where('name', 'Regular'))->count());
        $this->assertSame(8, Product::query()->whereHas('category', fn ($q) => $q->where('name', 'Premium'))->count());

        $this->assertPrice('44.00', 'DN-CHOCO-SPRINKLES');
        $this->assertPrice('49.00', 'DN-TIRAMISU');
        $this->assertPrice('279.00', 'BX-HALF-DOZEN');
        $this->assertPrice('549.00', 'BX-DOZEN');
        $this->assertPrice('99.00', 'CB-BETTER-TOGETHER');
        $this->assertPrice('150.00', 'MT-BREVON-CLASSIC');
        $this->assertPrice('110.00', 'CF-LATTE', 'Iced 16oz');
        $this->assertPrice('120.00', 'CF-LATTE', 'Hot 12oz');
        $this->assertPrice('110.00', 'CF-AMERICANO', 'Hot 12oz');
        $this->assertPrice('60.00', 'LM-CLASSIC', '16oz');
        $this->assertPrice('70.00', 'LM-CLASSIC', '22oz');

        // Seasalt Latte is iced only.
        $seasalt = Product::query()->where('sku', 'CF-SEASALT-LATTE')->sole();
        $this->assertSame(['Iced 16oz'], $seasalt->variants()->pluck('name')->all());

        // Doughnuts carry stock; the default size is the first one on the board.
        $doughnut = Product::query()->where('sku', 'DN-KITKAT')->sole();
        $this->assertSame(InventoryTrackingMode::FinishedStock, $doughnut->inventory_tracking_mode);
        $this->assertTrue(InventoryItem::query()->where('sku', 'DN-KITKAT')->exists());
        $this->assertSame('Iced 16oz', Product::query()->where('sku', 'CF-LATTE')->sole()->variants()->where('is_default', true)->value('name'));

        // D-177: seven top-level categories, in menu order, and nothing else.
        $this->assertSame(
            ['Regular', 'Premium', 'Assorted boxes', 'Coffee', 'Matcha series', 'Lemonade series', 'Combos'],
            Category::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(0, Category::query()->whereNotNull('parent_id')->count());
    }

    public function test_running_again_adds_nothing_and_keeps_changes_made_in_the_app(): void
    {
        $this->staff(PermissionCatalog::OWNER);
        $this->seed(MenuSeeder::class);
        Product::query()->where('sku', 'DN-TIRAMISU')->update(['name' => 'Tiramisu (renamed)']);
        $prices = ProductPrice::query()->count();
        $categories = Category::query()->count();

        $this->seed(MenuSeeder::class);

        $this->assertSame(36, Product::query()->count());
        $this->assertSame($prices, ProductPrice::query()->count());
        $this->assertSame($categories, Category::query()->count());
        $this->assertSame('Tiramisu (renamed)', Product::query()->where('sku', 'DN-TIRAMISU')->value('name'));
    }

    public function test_full_seeding_loads_the_menu_in_every_environment_once_an_owner_exists(): void
    {
        $this->staff(PermissionCatalog::OWNER);

        foreach (['staging', 'production'] as $env) {
            $this->app['env'] = $env;
            // Invoke directly: `db:seed` itself asks for confirmation in production.
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
            $this->assertSame(36, Product::query()->count(), $env);
        }
    }

    public function test_creates_the_default_owners_if_missing_and_records_harris_as_the_price_setter(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(36, Product::query()->count());
        $this->assertSame(3, User::role(PermissionCatalog::OWNER)->count());
        $harris = User::query()->where('username', 'harris')->sole();
        $this->assertSame(0, ProductPrice::query()->where('created_by', '!=', $harris->id)->count());
    }

    private function assertPrice(string $expected, string $sku, ?string $variant = null): void
    {
        $product = Product::query()->where('sku', $sku)->sole();
        $size = $variant === null ? null : $product->variants()->where('name', $variant)->sole();

        $price = app(PriceResolver::class)->current($product, $size, $this->mainLocation());

        $this->assertSame($expected, $price?->toDecimal(), "{$sku} {$variant}");
    }
}
