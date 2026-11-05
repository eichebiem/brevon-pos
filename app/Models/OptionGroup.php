<?php

namespace App\Models;

use App\Enums\OptionSelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property OptionSelectionType $selection_type
 * @property int $min_selections
 * @property int|null $max_selections
 * @property bool $is_active
 */
class OptionGroup extends Model
{
    protected $fillable = ['name', 'selection_type', 'min_selections', 'max_selections', 'is_active'];

    protected function casts(): array
    {
        return [
            'selection_type' => OptionSelectionType::class,
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group')->withPivot('sort_order')->withTimestamps();
    }
}
