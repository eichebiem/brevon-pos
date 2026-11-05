<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\PriceService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Price history per product (FR-019). View: products.view; change: products.manage-prices. */
class ProductPriceController extends Controller
{
    public function __construct(private readonly PriceService $prices) {}

    public function index(Request $request, Product $product): Response
    {
        $rows = $product->prices()->with(['variant:id,name', 'branch:id,code', 'creator:id,name'])
            ->orderByDesc('effective_from')->orderByDesc('id')->limit(200)->get()
            ->map(fn (ProductPrice $p) => [
                'id' => $p->id,
                'price' => $p->price,
                'variant' => $p->variant?->name,
                'location' => $p->branch?->code,
                'effective_from' => $p->effective_from->toIso8601String(),
                'effective_until' => $p->effective_until?->toIso8601String(),
                'is_current' => $p->effective_until === null,
                'is_base' => $p->variant_id === null && $p->branch_id === null,
                'created_by' => $p->creator?->name,
            ]);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/products/Prices', [
            'product' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'is_active' => $product->is_active],
            'prices' => $rows,
            'variants' => $product->variants()->get()->map(fn (ProductVariant $v) => ['id' => $v->id, 'name' => $v->name, 'is_active' => $v->is_active]),
            'locations' => $product->locations()->wherePivot('is_available', true)->orderBy('code')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name]),
            'canManage' => $user->can('products.manage-prices'),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'string', 'max:12'],
            'variant_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $variant = isset($validated['variant_id']) ? $product->variants()->whereKey((int) $validated['variant_id'])->firstOrFail() : null;
        $location = isset($validated['branch_id']) ? Branch::query()->findOrFail((int) $validated['branch_id']) : null;

        /** @var User $actor */
        $actor = $request->user();
        $this->prices->set($actor, $product, (string) $validated['price'], $variant, $location);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price updated. It applies to new sales from now on.')]);

        return back();
    }

    public function end(Request $request, ProductPrice $price): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->prices->end($actor, $price);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Special price removed; the next price in line applies.')]);

        return back();
    }
}
