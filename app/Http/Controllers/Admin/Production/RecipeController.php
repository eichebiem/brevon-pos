<?php

namespace App\Http\Controllers\Admin\Production;

use App\Domain\Production\RecipeBook;
use App\Enums\InventoryTrackingMode;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Recipes and their versions (FR-064–FR-066, FR-072; D-140, D-141). */
class RecipeController extends Controller
{
    public function __construct(private readonly RecipeBook $recipes) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'missing' => ['nullable', 'boolean']]);
        $active = Recipe::query()->where('is_active', true)->get(['id', 'product_id', 'product_variant_id', 'version', 'output_quantity', 'output_unit', 'created_at'])->groupBy('product_id');

        $rows = $this->recipes->products()
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'), 'category:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(function (Product $p) use ($active) {
                $recipes = $active->get($p->id, collect());
                $all = $recipes->firstWhere('product_variant_id', null);

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category?->name,
                    'at_sale' => $p->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale,
                    'recipe' => $all ? ['version' => $all->version, 'makes' => $all->output_quantity, 'unit' => $all->output_unit] : null,
                    'sizes' => $p->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale ? $p->variants->map(fn (ProductVariant $v) => [
                        'id' => $v->id,
                        'name' => $v->name,
                        'version' => $recipes->firstWhere('product_variant_id', $v->id)?->version,
                    ])->values() : [],
                ];
            })
            ->values();

        if ((bool) ($filters['missing'] ?? false)) {
            $rows = $rows->filter(fn (array $r) => $r['recipe'] === null && collect($r['sizes'])->whereNotNull('version')->isEmpty())->values();
        }

        return Inertia::render('admin/production/recipes/Index', [
            'rows' => $rows,
            'filters' => (object) $filters,
        ]);
    }

    public function show(Product $product): Response
    {
        abort_unless($this->recipes->products()->whereKey($product->id)->exists(), 404);
        $versions = Recipe::query()->where('product_id', $product->id)
            ->with(['items.item:id,sku,name', 'items.unit:id,code', 'variant:id,name', 'creator:id,name'])
            ->orderByRaw('product_variant_id IS NOT NULL')->orderBy('product_variant_id')->orderByDesc('version')->get();

        return Inertia::render('admin/production/recipes/Show', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'at_sale' => $product->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale,
                'sizes' => $product->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale
                    ? $product->variants()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']) : [],
            ],
            'versions' => $versions->map(fn (Recipe $r) => [
                'id' => $r->id,
                'size' => $r->variant?->name,
                'size_id' => $r->product_variant_id,
                'version' => $r->version,
                'makes' => $r->output_quantity,
                'unit' => $r->output_unit,
                'is_active' => $r->is_active,
                'effective_from' => $r->effective_from->toIso8601String(),
                'effective_until' => $r->effective_until?->toIso8601String(),
                'notes' => $r->notes,
                'created_by' => $r->creator?->name,
                'lines' => $r->items->map(fn (RecipeItem $l) => [
                    'sku' => $l->item?->sku,
                    'name' => $l->item?->name,
                    'quantity' => $l->quantity_required,
                    'unit' => $l->unit?->code,
                    'wastage_percent' => $l->wastage_percent,
                ])->values(),
            ])->values(),
        ]);
    }

    public function create(Request $request, Product $product): Response
    {
        abort_unless($this->recipes->products()->whereKey($product->id)->exists(), 404);
        $data = $request->validate(['size' => ['nullable', 'integer']]);
        $variant = isset($data['size']) ? $product->variants()->whereKey((int) $data['size'])->firstOrFail() : null;
        $current = Recipe::query()->where('product_id', $product->id)->where('is_active', true)
            ->when($variant, fn ($q) => $q->where('product_variant_id', $variant?->id), fn ($q) => $q->whereNull('product_variant_id'))
            ->with('items')->first();
        $product->load('inventoryItem.unit');

        return Inertia::render('admin/production/recipes/Form', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'at_sale' => $product->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale,
                'unit' => $product->inventoryItem?->unit?->code,
            ],
            'size' => $variant?->only(['id', 'name']),
            'current' => $current ? [
                'version' => $current->version,
                'makes' => $current->output_quantity,
                'notes' => $current->notes,
                'lines' => $current->items->map(fn (RecipeItem $l) => [
                    'inventory_item_id' => $l->inventory_item_id,
                    'quantity' => rtrim(rtrim($l->quantity_required, '0'), '.'),
                    'wastage_percent' => rtrim(rtrim($l->wastage_percent, '0'), '.'),
                ])->values(),
            ] : null,
            'ingredients' => $this->recipes->ingredients()->with('unit')->orderBy('name')->get()
                ->reject(fn (InventoryItem $i) => $i->id === $product->inventory_item_id)
                ->map(fn (InventoryItem $i) => ['id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit?->code])->values(),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($this->recipes->products()->whereKey($product->id)->exists(), 404);
        $data = $request->validate([
            'size_id' => ['nullable', 'integer'],
            'output_quantity' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.wastage_percent' => ['nullable', 'string', 'max:10'],
        ], ['lines.required' => __('Add at least one ingredient with a quantity.')]);
        $variant = isset($data['size_id']) ? ProductVariant::query()->findOrFail((int) $data['size_id']) : null;

        /** @var User $user */
        $user = $request->user();
        $recipe = $this->recipes->save($user, $product, $variant, $data['output_quantity'] ?? null, array_values(array_map(fn (array $l) => [
            'inventory_item_id' => (int) $l['inventory_item_id'],
            'quantity' => $l['quantity'] ?? null,
            'wastage_percent' => $l['wastage_percent'] ?? null,
        ], $data['lines'])), $data['notes'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recipe version :version saved.', ['version' => $recipe->version])]);

        return to_route('admin.recipes.show', $product);
    }
}
