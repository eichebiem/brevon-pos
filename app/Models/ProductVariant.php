<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $product_id
 * @property string|null $sku
 * @property string $name
 * @property array<string, mixed>|null $attributes
 * @property int|null $inventory_item_id
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
class ProductVariant extends Model
{
    protected $fillable = ['product_id', 'sku', 'name', 'attributes', 'inventory_item_id', 'is_default', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function delete(): never
    {
        throw new LogicException('Variants cannot be deleted; deactivate them instead.');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
