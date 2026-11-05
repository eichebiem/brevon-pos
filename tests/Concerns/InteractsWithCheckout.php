<?php

namespace Tests\Concerns;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\PriceService;
use App\Domain\Shared\Money;
use App\Domain\Shifts\ShiftService;
use App\Enums\OptionSelectionType;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\InventoryItem;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a sellable catalog and a cashier with an open shift, and posts
 * checkouts the way the POS does.
 */
trait InteractsWithCheckout
{
    use InteractsWithStaff;
    use InteractsWithStock;

    protected User $owner;

    protected function setUpCheckout(): void
    {
        $this->seedAccess();
        $this->seed(PaymentMethodSeeder::class);
        $this->owner = $this->staff(PermissionCatalog::OWNER);
    }

    protected function cashierOnShift(?Branch $location = null, string $openingCash = '1000.00', string $role = PermissionCatalog::CASHIER): User
    {
        $location ??= $this->mainLocation();
        $terminal = Terminal::query()->firstOrCreate(
            ['branch_id' => $location->id, 'terminal_code' => 'POS-1'],
            ['name' => 'Counter', 'is_active' => true],
        );
        $cashier = $this->staff($role, $location);
        app(ShiftService::class)->open($cashier, $terminal, Money::fromDecimal($openingCash));

        return $cashier;
    }

    protected function shiftOf(User $cashier): CashierShift
    {
        return CashierShift::query()->where('user_id', $cashier->id)->latest('id')->firstOrFail();
    }

    /** A doughnut sold at the location, with stock on hand. */
    protected function doughnut(string $price = '44.00', string $stock = '10', ?Branch $location = null, string $name = 'Choco Custard'): Product
    {
        $location ??= $this->mainLocation();
        $product = Product::factory()->finishedStock()->create(['name' => $name]);
        $this->sellAt($product, $location, $price);

        if ($stock !== '0') {
            /** @var InventoryItem $item */
            $item = $product->inventoryItem;
            $this->postStock($this->owner, $location, $item, $stock);
        }

        return $product;
    }

    /**
     * A made-to-order coffee with sizes and an optional add-on group.
     *
     * @return array{product: Product, iced: ProductVariant, hot: ProductVariant, shot: ProductOption, oat: ProductOption, group: OptionGroup}
     */
    protected function coffee(): array
    {
        $product = Product::factory()->create(['name' => 'Latte', 'sku' => 'CF-LATTE']);
        $iced = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Iced 16oz', 'sku' => 'CF-LATTE-ICED', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
        $hot = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Hot 12oz', 'sku' => 'CF-LATTE-HOT', 'is_default' => false, 'is_active' => true, 'sort_order' => 1]);
        $this->sellAt($product, $this->mainLocation(), '110.00');
        app(PriceService::class)->set($this->owner, $product, '120.00', $hot);

        $group = OptionGroup::query()->create(['name' => 'Add-ons', 'selection_type' => OptionSelectionType::Multiple, 'min_selections' => 0, 'max_selections' => 2, 'is_active' => true]);
        $shot = ProductOption::query()->create(['option_group_id' => $group->id, 'name' => 'Extra shot', 'price_delta' => '25.00', 'is_active' => true, 'sort_order' => 0]);
        $oat = ProductOption::query()->create(['option_group_id' => $group->id, 'name' => 'Oat milk', 'price_delta' => '30.00', 'is_active' => true, 'sort_order' => 1]);
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        return compact('product', 'iced', 'hot', 'shot', 'oat', 'group');
    }

    protected function sellAt(Product $product, Branch $location, string $price): void
    {
        $product->locations()->syncWithoutDetaching([$location->id => ['is_available' => true]]);
        if (! $product->prices()->whereNull('variant_id')->whereNull('branch_id')->exists()) {
            app(PriceService::class)->set($this->owner, $product, $price);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function payload(array $items, array $payment = ['method' => 'CASH', 'amount_tendered' => '1000'], array $extra = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'order_type' => 'walk_in',
            'items' => $items,
            'payment' => $payment,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function checkout(User $cashier, array $payload): TestResponse
    {
        return $this->actingAs($cashier)->post('/pos/checkout', $payload);
    }
}
