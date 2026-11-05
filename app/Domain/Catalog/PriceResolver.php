<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\Money;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Current price lookup (ERD §2). Most specific wins:
 * variant + location → variant → product + location → product.
 */
class PriceResolver
{
    public function current(Product $product, ?ProductVariant $variant, Branch $location, ?DateTimeInterface $at = null): ?Money
    {
        $row = $this->currentRow($product, $variant, $location, $at);

        return $row === null ? null : Money::fromDecimal($row->price);
    }

    public function currentRow(Product $product, ?ProductVariant $variant, Branch $location, ?DateTimeInterface $at = null): ?ProductPrice
    {
        $at = CarbonImmutable::instance($at ?? now());

        return ProductPrice::query()
            ->where('product_id', $product->id)
            ->where(fn ($q) => $q->whereNull('variant_id')->when($variant, fn ($q) => $q->orWhere('variant_id', $variant?->id)))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $location->id))
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByRaw('variant_id IS NULL')
            ->orderByRaw('branch_id IS NULL')
            ->first();
    }
}
