<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Categories (FR-015). Two levels at most (a parent and its children),
 * archived rather than deleted (D-086).
 */
class CategoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, parent_id: int|null, description: string|null, sort_order: int}  $data
     */
    public function create(User $actor, array $data): Category
    {
        $this->assertValidParent(null, $data['parent_id']);

        return DB::transaction(function () use ($actor, $data) {
            $category = Category::query()->create([...$data, 'slug' => $this->uniqueSlug($data['name']), 'is_active' => true]);
            $this->audit->record('category.created', $category, null, $this->snapshot($category), actor: $actor);

            return $category;
        });
    }

    /**
     * @param  array{name: string, parent_id: int|null, description: string|null, sort_order: int}  $data
     */
    public function update(User $actor, Category $category, array $data): Category
    {
        $this->assertValidParent($category, $data['parent_id']);

        return DB::transaction(function () use ($actor, $category, $data) {
            $before = $this->snapshot($category);
            $category->fill($data);
            if ($category->isDirty('name')) {
                $category->slug = $this->uniqueSlug($data['name'], $category->id);
            }
            $category->save();

            [$old, $new] = AuditDiff::between($before, $this->snapshot($category));
            if ($new !== []) {
                $this->audit->record('category.updated', $category, $old, $new, actor: $actor);
            }

            return $category;
        });
    }

    public function setActive(User $actor, Category $category, bool $active): Category
    {
        if ($active === $category->is_active) {
            return $category;
        }

        if (! $active) {
            if ($category->products()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['category' => __('Archive or move this category\'s active products first.')]);
            }
            if ($category->children()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['category' => __('Archive its sub-categories first.')]);
            }
        } elseif ($category->parent !== null && ! $category->parent->is_active) {
            throw ValidationException::withMessages(['category' => __('Reactivate the parent category first.')]);
        }

        $category->forceFill(['is_active' => $active])->save();
        $this->audit->record($active ? 'category.reactivated' : 'category.archived', $category, ['is_active' => ! $active], ['is_active' => $active], actor: $actor);

        return $category;
    }

    private function assertValidParent(?Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Category::query()->find($parentId);

        $error = match (true) {
            $parent === null => __('The selected parent category does not exist.'),
            $category !== null && $parent->id === $category->id => __('A category cannot be its own parent.'),
            $parent->parent_id !== null => __('Sub-categories cannot have their own sub-categories.'),
            $category !== null && $category->children()->exists() => __('This category has sub-categories, so it cannot become one.'),
            ! $parent->is_active => __('The parent category is archived.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['parent_id' => $error]);
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($n = 2; Category::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return $category->only(['name', 'slug', 'parent_id', 'description', 'sort_order']);
    }
}
