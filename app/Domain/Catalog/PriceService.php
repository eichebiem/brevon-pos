<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Price history (FR-019, BR-015, D-087). A new price takes effect now and
 * closes the previous open price for the same scope (product, variant,
 * location). Rows are never edited otherwise and never deleted, so order
 * snapshots and history stay intact.
 */
class PriceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function set(User $actor, Product $product, string $amount, ?ProductVariant $variant = null, ?Branch $location = null): ProductPrice
    {
        if ($variant !== null && $variant->product_id !== $product->id) {
            throw ValidationException::withMessages(['variant_id' => __('This variant belongs to another product.')]);
        }

        $money = $this->parse($amount);
        $now = CarbonImmutable::now()->startOfSecond();

        return DB::transaction(function () use ($actor, $product, $variant, $location, $money, $now) {
            $scope = $this->scope($product, $variant, $location)->lockForUpdate()->orderByDesc('effective_from')->get();

            /** @var ProductPrice|null $latest */
            $latest = $scope->first();
            if ($latest !== null && $latest->effective_from->greaterThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['price' => __('This price was changed a moment ago. Please try again.')]);
            }

            $open = $scope->firstWhere('effective_until', null);
            if ($open instanceof ProductPrice) {
                $open->effective_until = $now;
                $open->save();
            }

            $price = ProductPrice::query()->create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'branch_id' => $location?->id,
                'price' => $money->toDecimal(),
                'effective_from' => $now,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('product.price_set', $product, [
                'price' => $open?->price,
            ], [
                'price' => $money->toDecimal(),
                'variant' => $variant?->name,
                'location' => $location->code ?? 'all locations',
            ], $location?->id, $actor);

            return $price;
        });
    }

    /**
     * End a variant- or location-specific price so the next price in line
     * applies again. The product's own all-locations price cannot be ended:
     * a product always keeps a base price once set.
     */
    public function end(User $actor, ProductPrice $price): ProductPrice
    {
        if ($price->variant_id === null && $price->branch_id === null) {
            throw ValidationException::withMessages(['price' => __('The base price cannot be removed; set a new price instead.')]);
        }

        if ($price->effective_until !== null) {
            throw ValidationException::withMessages(['price' => __('This price has already ended.')]);
        }

        $now = CarbonImmutable::now()->startOfSecond();
        if ($price->effective_from->greaterThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['price' => __('This price was set a moment ago. Please try again.')]);
        }

        return DB::transaction(function () use ($actor, $price, $now) {
            $price->effective_until = $now;
            $price->save();

            /** @var Product $product */
            $product = $price->product;
            $this->audit->record('product.price_ended', $product, [
                'price' => $price->price,
                'variant' => $price->variant?->name,
                'location' => $price->branch->code ?? 'all locations',
            ], null, $price->branch_id, $actor);

            return $price;
        });
    }

    /**
     * @return Builder<ProductPrice>
     */
    private function scope(Product $product, ?ProductVariant $variant, ?Branch $location)
    {
        return ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->where('branch_id', $location?->id);
    }

    private function parse(string $amount): Money
    {
        try {
            $money = Money::fromDecimal(trim($amount));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['price' => __('Enter a price like 45 or 45.50.')]);
        }

        if ($money->isNegative()) {
            throw ValidationException::withMessages(['price' => __('The price cannot be negative.')]);
        }

        if ($money->greaterThan(Money::fromDecimal('999999.99'))) {
            throw ValidationException::withMessages(['price' => __('The price is too large.')]);
        }

        return $money;
    }
}
