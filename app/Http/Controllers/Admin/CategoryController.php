<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\CategoryService;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Categories (FR-015). Routes: products.view / products.create / products.update / products.archive. */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): Response
    {
        $all = Category::query()->withCount([
            'products' => fn ($q) => $q->where('is_active', true),
        ])->orderBy('sort_order')->orderBy('name')->get();

        // Parents followed by their children, for an indented list.
        $rows = $all->whereNull('parent_id')->flatMap(fn (Category $parent) => collect([$parent])
            ->merge($all->where('parent_id', $parent->id)))
            ->map(fn (Category $c) => [
                ...$c->only(['id', 'name', 'slug', 'parent_id', 'sort_order', 'is_active']),
                'active_products' => $c->products_count,
            ])->values();

        return Inertia::render('admin/categories/Index', ['categories' => $rows]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categories/Form', ['category' => null, 'parents' => $this->parents()]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->categories->create($actor, $this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('admin.categories.index');
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('admin/categories/Form', [
            'category' => $category->only(['id', 'name', 'parent_id', 'description', 'sort_order', 'is_active']),
            'parents' => $this->parents($category),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->categories->update($actor, $category, $this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category saved.')]);

        return to_route('admin.categories.index');
    }

    public function setStatus(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var User $actor */
        $actor = $request->user();
        $this->categories->setActive($actor, $category, (bool) $validated['is_active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['is_active'] ? __('Category reactivated.') : __('Category archived.')]);

        return back();
    }

    /**
     * @return array{name: string, parent_id: int|null, description: string|null, sort_order: int}
     */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return [
            'name' => trim((string) $v['name']),
            'parent_id' => isset($v['parent_id']) ? (int) $v['parent_id'] : null,
            'description' => $v['description'] ?? null,
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ];
    }

    /**
     * Top-level active categories that may be chosen as a parent.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function parents(?Category $except = null)
    {
        return Category::query()->whereNull('parent_id')->where('is_active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name]);
    }
}
