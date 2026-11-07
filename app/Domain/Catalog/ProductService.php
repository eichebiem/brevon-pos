<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Enums\InventoryItemType;
use App\Enums\InventoryTrackingMode;
use App\Enums\LocationType;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Products, variants and per-location availability
 * (FR-016–FR-018, FR-020, FR-021; D-025, D-063, D-064, D-085, D-086).
 *
 * Expected $data keys: category_id, sku, name, description, product_type,
 * inventory_tracking_mode, base_unit, is_available_for_pos,
 * variants (list of {id?, name, sku?, is_default, is_active}),
 * location_ids (list), and on create base_price.
 */
class ProductService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PriceService $prices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?UploadedFile $image = null): Product
    {
        $this->assertConsistent($data);
        $stored = $image?->store('products', 'public') ?: null;

        try {
            return DB::transaction(function () use ($actor, $data, $stored) {
                $product = new Product;
                $product->fill([
                    ...$this->attributes($data),
                    'image_path' => $stored,
                    'is_active' => true,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $this->linkStockItem($product);
                $product->save();

                $this->syncVariants($product, $data['variants'] ?? []);
                $this->syncLocations($product, $data['location_ids'] ?? []);

                $this->audit->record('product.created', $product, null, $this->snapshot($product), actor: $actor);
                $this->prices->set($actor, $product, (string) $data['base_price']);

                return $product;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('public')->delete($stored);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Product $product, array $data, ?UploadedFile $image = null, bool $removeImage = false): Product
    {
        $this->assertConsistent($data);
        $oldImage = $product->image_path;
        $stored = $image?->store('products', 'public') ?: null;

        try {
            $product = DB::transaction(function () use ($actor, $product, $data, $stored, $removeImage, $oldImage) {
                $before = $this->snapshot($product);

                $product->fill([...$this->attributes($data), 'updated_by' => $actor->id]);
                $product->image_path = $stored ?? ($removeImage ? null : $oldImage);
                $this->linkStockItem($product);
                $product->save();

                $this->syncVariants($product, $data['variants'] ?? []);
                $this->syncLocations($product, $data['location_ids'] ?? []);

                [$old, $new] = AuditDiff::between($before, $this->snapshot($product->refresh()));
                if ($new !== []) {
                    $this->audit->record('product.updated', $product, $old, $new, actor: $actor);
                }

                return $product;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('public')->delete($stored);
            }
            throw $e;
        }

        if ($oldImage !== null && $oldImage !== $product->image_path) {
            Storage::disk('public')->delete($oldImage);
        }

        return $product;
    }

    /** Archive / reactivate (FR-016, BR-014). */
    public function setActive(User $actor, Product $product, bool $active): Product
    {
        if ($active === $product->is_active) {
            return $product;
        }

        if ($active && ! $product->category()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['product' => __('Reactivate the product\'s category first.')]);
        }

        $product->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
        $this->audit->record($active ? 'product.reactivated' : 'product.archived', $product, ['is_active' => ! $active], ['is_active' => $active], actor: $actor);

        return $product;
    }

    /** Temporarily unavailable (sold out) without archiving (FR-020). */
    public function setAvailableForPos(User $actor, Product $product, bool $available): Product
    {
        if ($available === $product->is_available_for_pos) {
            return $product;
        }

        $product->forceFill(['is_available_for_pos' => $available, 'updated_by' => $actor->id])->save();
        $this->audit->record('product.pos_availability_changed', $product, ['is_available_for_pos' => ! $available], ['is_available_for_pos' => $available], actor: $actor);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConsistent(array $data): void
    {
        $type = ProductType::from((string) $data['product_type']);
        $mode = InventoryTrackingMode::from((string) $data['inventory_tracking_mode']);

        if (! in_array($mode, $type->allowedTrackingModes(), true)) {
            throw ValidationException::withMessages(['inventory_tracking_mode' => __('":mode" is not allowed for :type products.', [
                'mode' => $mode->label(),
                'type' => strtolower($type->label()),
            ])]);
        }

        $category = Category::query()->find((int) $data['category_id']);
        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages(['category_id' => __('Choose an active category.')]);
        }

        /** @var list<int> $locationIds */
        $locationIds = $data['location_ids'] ?? [];
        if ($mode !== InventoryTrackingMode::FinishedStock) {
            $popUps = Branch::query()->whereIn('id', $locationIds)->where('location_type', LocationType::PopUp)->pluck('code');
            if ($popUps->isNotEmpty()) {
                throw ValidationException::withMessages(['location_ids' => __('Pop-up stands only sell finished-stock items delivered from the main branch. Remove: :codes.', [
                    'codes' => $popUps->implode(', '),
                ])]);
            }
        }

        /** @var list<array<string, mixed>> $variants */
        $variants = $data['variants'] ?? [];
        $defaults = array_filter($variants, fn ($v) => ! empty($v['is_default']));
        if (count($defaults) > 1) {
            throw ValidationException::withMessages(['variants' => __('Only one variant can be the default.')]);
        }
        foreach ($defaults as $variant) {
            if (empty($variant['is_active'])) {
                throw ValidationException::withMessages(['variants' => __('The default variant must be active.')]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'category_id' => (int) $data['category_id'],
            'sku' => strtoupper(trim((string) $data['sku'])),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'product_type' => $data['product_type'],
            'inventory_tracking_mode' => $data['inventory_tracking_mode'],
            'base_unit' => $data['base_unit'] ?? 'piece',
            'is_available_for_pos' => (bool) ($data['is_available_for_pos'] ?? true),
        ];
    }

    /**
     * D-025: finished-stock products need their own stock item. One is
     * created automatically (same SKU) the first time it is needed.
     */
    private function linkStockItem(Product $product): void
    {
        if ($product->inventory_tracking_mode !== InventoryTrackingMode::FinishedStock || $product->inventory_item_id !== null) {
            return;
        }

        if (InventoryItem::query()->where('sku', $product->sku)->exists()) {
            throw ValidationException::withMessages(['sku' => __('A stock item with SKU :sku already exists; use a different product SKU.', ['sku' => $product->sku])]);
        }

        $unit = UnitOfMeasure::query()->firstOrCreate(['code' => 'pc'], ['name' => 'Piece', 'precision' => 0]);

        $product->inventory_item_id = InventoryItem::query()->create([
            'sku' => $product->sku,
            'name' => $product->name,
            'item_type' => $product->product_type === ProductType::Retail ? InventoryItemType::Retail : InventoryItemType::FinishedGood,
            'unit_of_measure_id' => $unit->id,
            'is_perishable' => $product->product_type === ProductType::FinishedGood,
        ])->id;
    }

    /**
     * Existing variants are updated (never deleted, D-086); new rows are added.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        $existing = $product->variants()->get()->keyBy('id');

        // Clear the old default first so the one-default guard is never hit mid-update.
        $product->variants()->where('is_default', true)->update(['is_default' => false]);

        $hasDefault = collect($rows)->contains(fn ($row) => ! empty($row['is_default']));

        foreach ($rows as $position => $row) {
            $isActive = (bool) ($row['is_active'] ?? true);
            $attributes = [
                'name' => trim((string) $row['name']),
                'sku' => filled($row['sku'] ?? null) ? strtoupper(trim((string) $row['sku'])) : null,
                'is_active' => $isActive,
                'is_default' => $hasDefault ? ! empty($row['is_default']) : false,
                'sort_order' => $position,
            ];

            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id !== null && $existing->has($id)) {
                /** @var ProductVariant $variant */
                $variant = $existing->get($id);
                $variant->fill($attributes)->save();
            } elseif ($id === null) {
                $product->variants()->create($attributes);
            } else {
                throw ValidationException::withMessages(['variants' => __('A variant does not belong to this product.')]);
            }
        }

        // Without an explicit choice the first active variant is the default.
        if (! $hasDefault) {
            $product->variants()->where('is_active', true)->orderBy('sort_order')->limit(1)->update(['is_default' => true]);
        }
    }

    /**
     * Rows are kept (is_available toggled) so availability history is preserved.
     *
     * @param  list<int>  $locationIds
     */
    private function syncLocations(Product $product, array $locationIds): void
    {
        $wanted = array_map('intval', $locationIds);
        $now = now();

        foreach (Branch::query()->pluck('id') as $branchId) {
            $available = in_array($branchId, $wanted, true);
            $exists = DB::table('product_branch')->where(['product_id' => $product->id, 'branch_id' => $branchId]);

            if ($exists->exists()) {
                $exists->update(['is_available' => $available, 'updated_at' => $now]);
            } elseif ($available) {
                DB::table('product_branch')->insert(['product_id' => $product->id, 'branch_id' => $branchId, 'is_available' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $product->load(['variants', 'locations']);

        return [
            ...$product->only(['sku', 'name', 'description', 'category_id', 'base_unit', 'image_path', 'is_available_for_pos', 'inventory_item_id']),
            'product_type' => $product->product_type->value,
            'inventory_tracking_mode' => $product->inventory_tracking_mode->value,
            'variants' => $product->variants->map(fn (ProductVariant $v) => $v->name.($v->is_default ? ' (default)' : '').($v->is_active ? '' : ' (inactive)'))->all(),
            'locations' => $product->locations->filter(fn (Branch $b) => (bool) $b->getRelationValue('pivot')?->getAttribute('is_available'))->pluck('code')->values()->all(),
        ];
    }
}
