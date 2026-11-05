<?php

namespace App\Models;

use App\Enums\InventoryTrackingMode;
use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Sellable product (FR-016, FR-017). Archived (is_active=false), never
 * deleted, so historical order items keep their link (BR-014, D-086).
 *
 * @property int $id
 * @property int $category_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property ProductType $product_type
 * @property InventoryTrackingMode $inventory_tracking_mode
 * @property int|null $inventory_item_id
 * @property string $base_unit
 * @property string|null $image_path
 * @property bool $is_active
 * @property bool $is_available_for_pos
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id', 'sku', 'name', 'description', 'product_type', 'inventory_tracking_mode',
        'inventory_item_id', 'base_unit', 'image_path', 'is_active', 'is_available_for_pos',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'inventory_tracking_mode' => InventoryTrackingMode::class,
            'is_active' => 'boolean',
            'is_available_for_pos' => 'boolean',
        ];
    }

    public function delete(): never
    {
        throw new LogicException('Products cannot be deleted; archive them instead.');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Per-location availability rows (D-064).
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'product_branch')->withPivot('is_available')->withTimestamps();
    }

    /**
     * @return BelongsToMany<OptionGroup, $this>
     */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_group')
            ->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Pop-ups only sell finished-stock items dispatched from the main branch (D-063). */
    public function canBeSoldAtPopUp(): bool
    {
        return $this->inventory_tracking_mode === InventoryTrackingMode::FinishedStock;
    }
}
