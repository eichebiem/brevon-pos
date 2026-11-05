<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\CategoryService;
use App\Domain\Catalog\PriceService;
use App\Domain\Catalog\ProductService;
use App\Domain\Shared\Money;
use App\Enums\InventoryTrackingMode;
use App\Enums\LocationType;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The shop's current menu, from the client's menu boards (D-101).
 *
 * Products are created through ProductService, so stock items, price
 * history and audit entries are the same as when entered in the admin
 * screens. Idempotent: categories are reused by name and a product whose SKU
 * already exists is skipped, so prices or names changed in the app are never
 * overwritten. Products are made available at the permanent branch only;
 * pop-ups get doughnuts through "Copy main branch doughnuts".
 *
 * Runs in every environment: this is the starting catalog up to and
 * including production. Needs the roles and the permanent location
 * (RolesAndPermissionsSeeder, LocationSeeder).
 */
class MenuSeeder extends Seeder
{
    /** Doughnut prices given by the owner (not printed on the board). */
    private const REGULAR = '44.00';

    private const PREMIUM = '49.00';

    public function __construct(
        private readonly CategoryService $categories,
        private readonly ProductService $products,
        private readonly PriceService $prices,
    ) {}

    public function run(): void
    {
        // Prices and audit entries record who set them: the default Owner
        // Harris (D-102), created here if the owners are not seeded yet.
        if (! User::role(PermissionCatalog::OWNER)->exists()) {
            $this->call(DefaultOwnersSeeder::class);
        }
        $actor = User::query()->where('username', DefaultOwnersSeeder::ACCOUNTS[0]['username'])->first()
            ?? User::role(PermissionCatalog::OWNER)->orderBy('id')->firstOrFail();
        $main = Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();

        $created = 0;
        foreach (self::menu() as $section) {
            $category = $this->category($actor, $section['parent'], $section['category']);

            foreach ($section['items'] as $item) {
                $created += $this->product($actor, $main, $category, $section, $item) ? 1 : 0;
            }
        }

        $this->command?->info("Menu ready: {$created} product(s) added.");
    }

    /**
     * @param  array{type: ProductType, mode: InventoryTrackingMode, unit: string, variants: list<string>}  $section
     * @param  array{sku: string, name: string, prices: list<string|null>}  $item
     */
    private function product(User $actor, Branch $main, Category $category, array $section, array $item): bool
    {
        if (Product::query()->where('sku', $item['sku'])->exists()) {
            return false;
        }

        // Only the sizes this item is sold in (e.g. Seasalt Latte has no hot version).
        $variants = [];
        foreach ($section['variants'] as $i => $name) {
            if ($item['prices'][$i] !== null) {
                $variants[] = ['name' => $name, 'price' => $item['prices'][$i], 'sku' => $item['sku'].'-'.self::suffix($name)];
            }
        }

        $base = $variants === [] ? (string) $item['prices'][0] : $variants[0]['price'];

        $product = $this->products->create($actor, [
            'category_id' => $category->id,
            'sku' => $item['sku'],
            'name' => $item['name'],
            'product_type' => $section['type']->value,
            'inventory_tracking_mode' => $section['mode']->value,
            'base_unit' => $section['unit'],
            'is_available_for_pos' => true,
            'variants' => array_map(fn (array $v, int $i) => ['name' => $v['name'], 'sku' => $v['sku'], 'is_default' => $i === 0, 'is_active' => true], $variants, array_keys($variants)),
            'location_ids' => [$main->id],
            'base_price' => $base,
        ]);

        // A size that costs more or less than the default gets its own price.
        foreach ($product->variants()->get() as $variant) {
            $price = collect($variants)->firstWhere('name', $variant->name)['price'] ?? $base;
            if (! Money::fromDecimal($price)->equals(Money::fromDecimal($base))) {
                $this->prices->set($actor, $product, $price, $variant);
            }
        }

        return true;
    }

    private function category(User $actor, ?string $parentName, string $name): Category
    {
        $parent = $parentName === null ? null : $this->category($actor, null, $parentName);

        $existing = Category::query()->where('name', $name)->where('parent_id', $parent?->id)->first();

        return $existing ?? $this->categories->create($actor, [
            'name' => $name,
            'parent_id' => $parent?->id,
            'description' => null,
            'sort_order' => Category::query()->where('parent_id', $parent?->id)->max('sort_order') + 10,
        ]);
    }

    private static function suffix(string $variant): string
    {
        return match ($variant) {
            'Iced 16oz' => 'ICED',
            'Hot 12oz' => 'HOT',
            default => strtoupper((string) preg_replace('/[^0-9a-z]/i', '', $variant)),
        };
    }

    /**
     * The menu boards, section by section. `prices` follow `variants`
     * (null = size not sold); sections without variants have one price.
     *
     * @return list<array{parent: string|null, category: string, type: ProductType, mode: InventoryTrackingMode, unit: string, variants: list<string>, items: list<array{sku: string, name: string, prices: list<string|null>}>}>
     */
    public static function menu(): array
    {
        $doughnut = ['parent' => 'Doughnuts', 'type' => ProductType::FinishedGood, 'mode' => InventoryTrackingMode::FinishedStock, 'unit' => 'piece', 'variants' => []];
        $drink = ['type' => ProductType::MadeToOrder, 'mode' => InventoryTrackingMode::None, 'unit' => 'cup'];

        return [
            [...$doughnut, 'category' => 'Regular', 'items' => [
                ['sku' => 'DN-CHOCO-SPRINKLES', 'name' => 'Chocolate Sprinkles', 'prices' => [self::REGULAR]],
                ['sku' => 'DN-STRAW-SPRINKLES', 'name' => 'Strawberry Sprinkles', 'prices' => [self::REGULAR]],
                ['sku' => 'DN-DULCE-DE-LECHE', 'name' => 'Dulce De Leche', 'prices' => [self::REGULAR]],
                ['sku' => 'DN-STRAW-FILLED', 'name' => 'Strawberry Filled', 'prices' => [self::REGULAR]],
                ['sku' => 'DN-NUTTY-DARK-CHOCO', 'name' => 'Nutty Dark Choco', 'prices' => [self::REGULAR]],
                ['sku' => 'DN-CHOCO-CUSTARD', 'name' => 'Choco Custard', 'prices' => [self::REGULAR]],
            ]],
            [...$doughnut, 'category' => 'Premium', 'items' => [
                ['sku' => 'DN-KITKAT', 'name' => 'Kitkat Doughnut', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-STRAW-SHORTCAKE', 'name' => 'Strawberry Shortcake Doughnut', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-MATCHA-ALMONDS', 'name' => 'Matcha Almonds', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-COCONUT-CREAM', 'name' => 'Coconut Cream Doughnut', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-LOTUS-BISCOFF', 'name' => 'Lotus Biscoff Doughnut', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-NUTELLA-ALMONDS', 'name' => 'Nutella Almonds', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-COOKIES-N-CREAM', 'name' => 'Cookies N Cream', 'prices' => [self::PREMIUM]],
                ['sku' => 'DN-TIRAMISU', 'name' => 'Tiramisu', 'prices' => [self::PREMIUM]],
            ]],
            // Assorted boxes and the combo hold doughnuts of the customer's
            // choice; the box itself has no stock of its own (D-101, open item).
            ['parent' => 'Doughnuts', 'category' => 'Assorted boxes', 'type' => ProductType::MadeToOrder, 'mode' => InventoryTrackingMode::None, 'unit' => 'box', 'variants' => [], 'items' => [
                ['sku' => 'BX-HALF-DOZEN', 'name' => 'Assorted Half Dozen', 'prices' => ['279.00']],
                ['sku' => 'BX-DOZEN', 'name' => 'Assorted Dozen', 'prices' => ['549.00']],
            ]],
            [...$drink, 'parent' => null, 'category' => 'Coffee', 'variants' => ['Iced 16oz', 'Hot 12oz'], 'items' => [
                ['sku' => 'CF-BREVON-CLASSIC-LATTE', 'name' => 'Brevon Classic Latte', 'prices' => ['110.00', '120.00']],
                ['sku' => 'CF-LATTE', 'name' => 'Latte', 'prices' => ['110.00', '120.00']],
                ['sku' => 'CF-AMERICANO', 'name' => 'Americano', 'prices' => ['110.00', '110.00']],
                ['sku' => 'CF-CARAMEL-LATTE', 'name' => 'Caramel Latte', 'prices' => ['125.00', '135.00']],
                ['sku' => 'CF-HAZELNUT-LATTE', 'name' => 'Hazelnut Latte', 'prices' => ['125.00', '135.00']],
                ['sku' => 'CF-CAPPUCCINO', 'name' => 'Cappuccino', 'prices' => ['125.00', '135.00']],
                ['sku' => 'CF-FRENCH-VANILLA', 'name' => 'French Vanilla', 'prices' => ['125.00', '135.00']],
                ['sku' => 'CF-SEASALT-LATTE', 'name' => 'Seasalt Latte', 'prices' => ['125.00', null]],
                ['sku' => 'CF-SPANISH-LATTE', 'name' => 'Spanish Latte', 'prices' => ['125.00', '135.00']],
                ['sku' => 'CF-WHITE-MOCHA', 'name' => 'White Mocha', 'prices' => ['125.00', '135.00']],
            ]],
            [...$drink, 'parent' => 'Cold Drinks', 'category' => 'Matcha series', 'variants' => [], 'items' => [
                ['sku' => 'MT-BREVON-CLASSIC', 'name' => 'Brevon Classic Matcha', 'prices' => ['150.00']],
                ['sku' => 'MT-SEASALT', 'name' => 'Matcha Seasalt', 'prices' => ['150.00']],
                ['sku' => 'MT-STRAWBERRY', 'name' => 'Strawberry Matcha', 'prices' => ['140.00']],
                ['sku' => 'MT-COCONUT-CLOUD', 'name' => 'Coconut Matcha Cloud', 'prices' => ['140.00']],
            ]],
            [...$drink, 'parent' => 'Cold Drinks', 'category' => 'Lemonade series', 'variants' => ['16oz', '22oz'], 'items' => [
                ['sku' => 'LM-CLASSIC', 'name' => 'Classic Lemonade', 'prices' => ['60.00', '70.00']],
                ['sku' => 'LM-STRAWBERRY', 'name' => 'Strawberry Lemonade', 'prices' => ['70.00', '80.00']],
                ['sku' => 'LM-LYCHEE', 'name' => 'Lemonade Lychee', 'prices' => ['70.00', '80.00']],
                ['sku' => 'LM-CUCUMBER', 'name' => 'Cucumber Lemonade', 'prices' => ['70.00', '80.00']],
                ['sku' => 'LM-BLUE', 'name' => 'Blue Lemonade', 'prices' => ['70.00', '80.00']],
            ]],
            ['parent' => 'Other', 'category' => 'Combos', 'type' => ProductType::MadeToOrder, 'mode' => InventoryTrackingMode::None, 'unit' => 'set', 'variants' => [], 'items' => [
                ['sku' => 'CB-BETTER-TOGETHER', 'name' => 'Better Together (Lemonade 16oz + Doughnut)', 'prices' => ['99.00']],
            ]],
        ];
    }
}
