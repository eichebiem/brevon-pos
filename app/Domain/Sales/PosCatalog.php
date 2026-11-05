<?php

namespace App\Domain\Sales;

use App\Domain\Catalog\CatalogQuery;
use App\Domain\Catalog\PriceResolver;
use App\Enums\InventoryTrackingMode;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * What the cashier screen shows for one location (FR-024): sellable
 * products with current prices, sizes, add-ons and stock left, grouped under
 * top-level category tabs. Display only — checkout re-checks everything on
 * the server (FR-027).
 */
class PosCatalog
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * @return array{categories: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function for(Branch $location): array
    {
        /** @var Collection<int, Product> $products */
        $products = $this->catalog->sellableAt($location)
            ->with([
                'category.parent',
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'optionGroups' => fn ($q) => $q->where('is_active', true),
                'optionGroups.options' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        $stock = $this->stockAt($location, $products);

        $rows = $products->map(fn (Product $p) => $this->product($location, $p, $stock))
            ->filter(fn (array $p) => $p['price'] !== null)
            ->values();

        return [
            'categories' => $this->tabs($products),
            'products' => array_values($rows->sortBy([['tab_sort', 'asc'], ['group_sort', 'asc'], ['name', 'asc']])->all()),
        ];
    }

    /**
     * @param  array<int, string>  $stock
     * @return array<string, mixed>
     */
    private function product(Branch $location, Product $product, array $stock): array
    {
        $category = $product->category;
        $tab = $category->topLevel();
        $tracked = $product->inventory_tracking_mode === InventoryTrackingMode::FinishedStock;

        $variants = $product->variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'name' => $v->name,
            'price' => $this->prices->current($product, $v, $location)?->toDecimal(),
            'on_hand' => $tracked ? ($stock[$v->inventory_item_id ?? $product->inventory_item_id] ?? '0.000') : null,
        ])->filter(fn (array $v) => $v['price'] !== null)->values();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'image_url' => $product->imageUrl(),
            'tab_id' => $tab->id,
            'tab_sort' => $tab->sort_order,
            'group' => $category->parent === null ? null : $category->name,
            'group_sort' => $category->parent === null ? -1 : $category->sort_order,
            'price' => $variants->isNotEmpty()
                ? $variants->first()['price']
                : $this->prices->current($product, null, $location)?->toDecimal(),
            'prices_vary' => $variants->pluck('price')->unique()->count() > 1,
            'on_hand' => $tracked && $variants->isEmpty() ? ($stock[$product->inventory_item_id] ?? '0.000') : null,
            'variants' => $variants->all(),
            'option_groups' => $product->optionGroups->map(fn (OptionGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'single' => $g->selection_type->value === 'single',
                'min' => $g->min_selections,
                'max' => $g->max_selections,
                'options' => $g->options->map(fn (ProductOption $o) => ['id' => $o->id, 'name' => $o->name, 'price_delta' => $o->price_delta])->values()->all(),
            ])->filter(fn (array $g) => $g['options'] !== [])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{id: int, name: string}>
     */
    private function tabs(Collection $products): array
    {
        return array_values($products
            ->map(fn (Product $p) => $p->category->topLevel())
            ->unique('id')
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])
            ->all());
    }

    /**
     * Quantity on hand per stock item at this location.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, string>
     */
    private function stockAt(Branch $location, Collection $products): array
    {
        $itemIds = $products->pluck('inventory_item_id')
            ->merge($products->flatMap(fn (Product $p) => $p->variants->pluck('inventory_item_id')))
            ->filter()->unique()->values();

        /** @var array<int, string> */
        return BranchInventory::query()
            ->where('branch_id', $location->id)
            ->whereIn('inventory_item_id', $itemIds)
            ->pluck('quantity_on_hand', 'inventory_item_id')
            ->all();
    }
}
