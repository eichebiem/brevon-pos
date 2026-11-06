<?php

namespace App\Domain\Production;

use App\Domain\Inventory\QuantityInput;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryItemType;
use App\Enums\InventoryTrackingMode;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recipes (FR-064–FR-066, FR-072; D-140, D-141).
 *
 * Doughnuts (finished stock) have one recipe for the product: it says what
 * a batch of N pieces uses, and is used when a batch is posted. Drinks
 * (recipe at sale) have a recipe per serving, either for every size or for
 * one size; it is used at checkout. Saving a recipe always creates a new
 * version and closes the previous one, so past batches and sales keep the
 * recipe they used. Ingredient units are the stock item's own unit (D-049).
 */
class RecipeBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Products that can have a recipe.
     *
     * @return Builder<Product>
     */
    public function products(): Builder
    {
        return Product::query()->where('is_active', true)
            ->whereIn('inventory_tracking_mode', [InventoryTrackingMode::FinishedStock, InventoryTrackingMode::RecipeAtSale])
            ->where(fn (Builder $q) => $q->where('inventory_tracking_mode', InventoryTrackingMode::RecipeAtSale)
                ->orWhere('product_type', 'finished_good'));
    }

    /**
     * Items a recipe can use: active ingredients, packaging and retail items.
     *
     * @return Builder<InventoryItem>
     */
    public function ingredients(): Builder
    {
        return InventoryItem::query()->where('is_active', true)->where('item_type', '!=', InventoryItemType::FinishedGood);
    }

    /** The recipe in use: the size's own recipe first, else the one for every size. */
    public function active(Product $product, ?ProductVariant $variant = null): ?Recipe
    {
        $query = Recipe::query()->where('product_id', $product->id)->where('is_active', true);

        if ($variant !== null) {
            $own = (clone $query)->where('product_variant_id', $variant->id)->latest('version')->first();
            if ($own !== null) {
                return $own;
            }
        }

        return $query->whereNull('product_variant_id')->latest('version')->first();
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null, wastage_percent: string|null}>  $lines
     */
    public function save(User $actor, Product $product, ?ProductVariant $variant, ?string $outputQuantity, array $lines, ?string $notes): Recipe
    {
        $atSale = $product->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale;
        if (! $this->products()->whereKey($product->id)->exists()) {
            throw ValidationException::withMessages(['product' => __('Recipes are for doughnuts and for drinks that deduct ingredients at sale.')]);
        }
        if ($variant !== null && ($variant->product_id !== $product->id || ! $atSale)) {
            throw ValidationException::withMessages(['variant_id' => __('Size recipes are only for drinks that deduct ingredients at sale.')]);
        }

        if ($atSale) {
            $output = Quantity::fromInt(1);
            $outputUnit = 'serving';
        } else {
            $product->loadMissing('inventoryItem.unit');
            $precision = $product->inventoryItem->unit->precision ?? 0;
            $output = QuantityInput::parse((string) $outputQuantity, $precision);
            if ($output === null) {
                throw ValidationException::withMessages(['output_quantity' => __('Enter how many this recipe makes: :rule', ['rule' => QuantityInput::message($precision)])]);
            }
            $outputUnit = $product->inventoryItem->unit->code ?? 'piece';
        }

        $rows = $this->parseLines($lines, $product);

        return DB::transaction(function () use ($actor, $product, $variant, $output, $outputUnit, $rows, $notes) {
            // One writer per product at a time, so version numbers never clash.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $variantId = $variant?->id;
            $sameKey = Recipe::query()->where('product_id', $product->id)
                ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'));

            $previous = (clone $sameKey)->where('is_active', true)->latest('version')->first();
            $version = ((int) (clone $sameKey)->max('version')) + 1;
            $previous?->forceFill(['is_active' => false, 'effective_until' => now()])->save();

            $recipe = new Recipe;
            $recipe->forceFill([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'version' => $version,
                'output_quantity' => $output->toString(),
                'output_unit' => $outputUnit,
                'effective_from' => now(),
                'is_active' => true,
                'notes' => $notes,
                'created_by' => $actor->id,
            ])->save();

            foreach ($rows as $row) {
                (new RecipeItem)->forceFill([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $row['item']->id,
                    'quantity_required' => $row['quantity']->toString(),
                    'unit_of_measure_id' => $row['item']->unit_of_measure_id,
                    'wastage_percent' => $row['wastage'],
                ])->save();
            }

            $this->audit->record('recipe.saved', $recipe, $previous ? ['version' => $previous->version] : null, [
                'product' => $product->sku,
                'variant' => $variant?->name,
                'version' => $version,
                'makes' => $output->toString(),
                'lines' => count($rows),
            ], null, $actor);

            return $recipe;
        });
    }

    /**
     * Ingredients needed to make (or sell) a quantity: required × (1 +
     * wastage %) × quantity ÷ recipe output, rounded half-up to the item's
     * unit. Lines that round to nothing are left out.
     *
     * @return array<int, Quantity> keyed by inventory item id
     */
    public function ingredientsFor(Recipe $recipe, Quantity $made): array
    {
        $recipe->loadMissing('items.item.unit');
        $needs = [];
        foreach ($recipe->items as $line) {
            $precision = $line->item->unit->precision ?? 3;
            $factor = bcadd('1', bcdiv(self::number($line->wastage_percent), '100', 6), 6);
            $exact = bcdiv(bcmul(bcmul(self::number($line->quantity_required), $factor, 9), self::number($made->toString()), 9), self::number($recipe->output_quantity), 9);
            $half = bcdiv('5', bcpow('10', (string) ($precision + 1), 0), $precision + 1);
            $rounded = bcadd($exact, $half, $precision);
            $quantity = Quantity::fromString(bcadd($rounded, '0', 3));
            if ($quantity->isPositive()) {
                $needs[$line->inventory_item_id] = $quantity;
            }
        }

        return $needs;
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null, wastage_percent: string|null}>  $lines
     * @return list<array{item: InventoryItem, quantity: Quantity, wastage: string}>
     */
    private function parseLines(array $lines, Product $product): array
    {
        $lines = array_values(array_filter($lines, fn (array $l) => trim((string) $l['quantity']) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one ingredient with a quantity.')]);
        }

        $items = $this->ingredients()->with('unit')->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $id = (int) $line['inventory_item_id'];
            /** @var InventoryItem|null $item */
            $item = $items->get($id);
            if ($item === null || $id === $product->inventory_item_id || isset($seen[$id])) {
                $errors["lines.{$i}.quantity"] = isset($seen[$id]) ? __('Each ingredient can only be listed once.') : __('This item cannot be used in a recipe.');

                continue;
            }
            $seen[$id] = true;
            // Recipe amounts may be finer than the unit (half an egg per doughnut); use is rounded later.
            $quantity = QuantityInput::parse((string) $line['quantity'], 3);
            if ($quantity === null) {
                $errors["lines.{$i}.quantity"] = __('Enter how much :unit is used (more than 0, up to 3 decimal places).', ['unit' => $item->unit?->code]);

                continue;
            }
            $wastage = trim((string) ($line['wastage_percent'] ?? ''));
            $wastage = $wastage === '' ? '0' : $wastage;
            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $wastage) !== 1 || ! is_numeric($wastage) || bccomp($wastage, '100', 2) > 0) {
                $errors["lines.{$i}.wastage_percent"] = __('Enter a wastage of 0 to 100 percent.');

                continue;
            }
            $rows[] = ['item' => $item, 'quantity' => $quantity, 'wastage' => bcadd($wastage, '0', 4)];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @return numeric-string
     */
    private static function number(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Not a number: {$value}");
        }

        return $value;
    }
}
