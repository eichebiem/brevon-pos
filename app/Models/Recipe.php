<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One version of a recipe (FR-064–FR-066; D-140). Versions are never
 * edited: a change is a new version, and the previous one is closed
 * (effective_until, is_active = false) so past batches keep their recipe.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $version
 * @property string $output_quantity
 * @property string $output_unit
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class Recipe extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only closing a version is allowed.
        static::updating(function (Recipe $recipe) {
            if (array_diff(array_keys($recipe->getDirty()), ['effective_until', 'is_active', 'updated_at']) !== [] || $recipe->is_active) {
                throw new LogicException('Recipe versions cannot be changed; save a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Recipes are never deleted.'));
    }

    /**
     * @return HasMany<RecipeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
