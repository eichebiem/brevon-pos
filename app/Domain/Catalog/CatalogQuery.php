<?php

namespace App\Domain\Catalog;

use App\Models\Branch;
use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a location may sell (FR-021): active, marked available for the POS,
 * in an active category, enabled for this location, priced, and — at a
 * pop-up — finished stock only (D-063). Stock levels are applied by the POS
 * from Phase 4a.
 */
class CatalogQuery
{
    /**
     * @return Builder<Product>
     */
    public function sellableAt(Branch $location, ?DateTimeInterface $at = null): Builder
    {
        $at = CarbonImmutable::instance($at ?? now());

        return Product::query()
            ->where('products.is_active', true)
            ->where('products.is_available_for_pos', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->whereHas('locations', fn ($q) => $q->where('branches.id', $location->id)->where('product_branch.is_available', true))
            ->when(! $location->isPermanent(), fn ($q) => $q->where('inventory_tracking_mode', 'finished_stock'))
            ->whereHas('prices', fn ($q) => $q
                ->whereNull('variant_id')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $location->id))
                ->where('effective_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at)));
    }
}
