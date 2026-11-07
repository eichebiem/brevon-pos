<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\ProductService;
use App\Enums\InventoryTrackingMode;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Products (UC-002, FR-016–FR-021). */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,archived,unavailable'],
            'location' => ['nullable', 'integer'],
        ]);

        $products = Product::query()
            ->with(['category:id,name', 'locations' => fn ($q) => $q->where('product_branch.is_available', true)->select('branches.id', 'code')])
            ->withCount(['variants' => fn ($q) => $q->where('is_active', true)])
            ->addSelect(['base_price' => ProductPrice::query()->select('price')
                ->whereColumn('product_id', 'products.id')->whereNull('variant_id')->whereNull('branch_id')->whereNull('effective_until')->limit(1)])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category_id', $c))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'archived', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'unavailable', fn ($q) => $q->where('is_active', true)->where('is_available_for_pos', false))
            ->when($filters['location'] ?? null, fn ($q, $l) => $q->whereHas('locations', fn ($q) => $q->where('branches.id', $l)->where('product_branch.is_available', true)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category?->name,
                'product_type' => $p->product_type->value,
                'image_url' => $p->imageUrl(),
                'base_price' => $p->getAttribute('base_price'),
                'variants_count' => $p->variants_count,
                'locations' => $p->locations->pluck('code'),
                'is_active' => $p->is_active,
                'is_available_for_pos' => $p->is_available_for_pos,
            ]);

        return Inertia::render('admin/products/Index', [
            'products' => $products,
            'filters' => (object) $filters,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'locations' => Branch::query()->orderBy('location_type')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/products/Form', [...$this->formOptions(), 'product' => null]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $product = $this->products->create($actor, $request->validated(), $request->file('image'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $product->name])]);

        return to_route('admin.products.index');
    }

    public function edit(Product $product): Response
    {
        $product->load(['variants', 'locations', 'inventoryItem.unit']);

        return Inertia::render('admin/products/Form', [
            ...$this->formOptions($product),
            'product' => [
                ...$product->only(['id', 'category_id', 'sku', 'name', 'description', 'base_unit', 'is_active', 'is_available_for_pos']),
                'product_type' => $product->product_type->value,
                'inventory_tracking_mode' => $product->inventory_tracking_mode->value,
                'image_url' => $product->imageUrl(),
                'stock_item' => $product->inventoryItem ? [
                    'sku' => $product->inventoryItem->sku,
                    'name' => $product->inventoryItem->name,
                    'unit' => $product->inventoryItem->unit?->code,
                ] : null,
                'variants' => $product->variants->map(fn (ProductVariant $v) => $v->only(['id', 'name', 'sku', 'is_default', 'is_active'])),
                'location_ids' => $product->locations->filter(fn (Branch $b) => (bool) $b->getRelationValue('pivot')?->getAttribute('is_available'))->pluck('id')->values(),
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->products->update($actor, $product, $request->validated(), $request->file('image'), $request->boolean('remove_image'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return back();
    }

    public function setStatus(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var User $actor */
        $actor = $request->user();
        $this->products->setActive($actor, $product, (bool) $validated['is_active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['is_active'] ? __(':name reactivated.', ['name' => $product->name]) : __(':name archived. Past sales are unchanged.', ['name' => $product->name])]);

        return back();
    }

    public function setPosAvailability(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate(['is_available_for_pos' => ['required', 'boolean']]);

        /** @var User $actor */
        $actor = $request->user();
        $this->products->setAvailableForPos($actor, $product, (bool) $validated['is_available_for_pos']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['is_available_for_pos'] ? __(':name is available again.', ['name' => $product->name]) : __(':name marked unavailable.', ['name' => $product->name])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Product $product = null): array
    {
        return [
            'categories' => Category::query()->with('parent:id,name')
                ->where(fn ($q) => $q->where('is_active', true)->when($product, fn ($q) => $q->orWhere('id', $product?->category_id)))
                ->orderBy('name')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->parent ? "{$c->parent->name} › {$c->name}" : $c->name, 'is_active' => $c->is_active])
                ->sortBy('name')->values(),
            'locations' => Branch::query()->orderBy('location_type')->orderBy('code')->get()->map(fn (Branch $b) => [
                'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'type' => $b->location_type->value, 'is_active' => $b->is_active,
            ]),
            'types' => collect(ProductType::cases())->map(fn (ProductType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'modes' => collect($t->allowedTrackingModes())->map(fn (InventoryTrackingMode $m) => ['value' => $m->value, 'label' => $m->label()]),
            ]),
        ];
    }
}
