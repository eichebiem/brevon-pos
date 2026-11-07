<?php

namespace App\Domain\Sales;

use App\Domain\Catalog\CatalogQuery;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Discounts\DiscountRequest;
use App\Domain\Shared\Money;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validates a cart against the current catalog and prices it on the server
 * (FR-026, FR-027, BR-003): the product must be sellable at the location
 * (active, on the POS, in an active category, enabled here, finished stock
 * at a pop-up), the variant must be valid and active, and the price is
 * the one in force now. Line total = quantity × unit price, rounded half-up
 * to the centavo (D-034); there are no add-ons (D-166). A discount is applied
 * after (D-147); no tax is charged (D-165). Errors are keyed
 * `items.{index}` so the POS can point at the line.
 */
class CartPricer
{
    public const MAX_LINES = 100;

    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly PriceResolver $prices,
        private readonly DiscountCalculator $discounts,
    ) {}

    /**
     * @param  list<CartLine>  $lines
     */
    public function price(Branch $location, array $lines, ?DiscountRequest $discount = null): PricedCart
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('Add at least one item.')]);
        }

        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['items' => __('An order can have at most :max lines.', ['max' => self::MAX_LINES])]);
        }

        $ids = array_values(array_unique(array_map(fn (CartLine $l) => $l->productId, $lines)));

        /** @var Collection<int, Product> $products */
        $products = $this->catalog->sellableAt($location)
            ->whereKey($ids)
            ->with(['variants'])
            ->get()
            ->keyBy('id');

        $priced = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            try {
                $priced[] = $this->line($location, $products->get($line->productId), $line);
            } catch (CartLineInvalid $e) {
                $errors["items.{$i}"] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $subtotal = Money::sum(array_map(fn (PricedLine $l) => $l->lineTotal, $priced));

        // Discount (D-147), spread over the lines it covers.
        $applied = $discount === null ? null : $this->discounts->apply($discount, $priced);
        $lineDiscounts = array_fill(0, count($priced), Money::zero());
        if ($applied !== null) {
            $weights = [];
            foreach ($applied->lineIndexes as $i) {
                $weights[$i] = $priced[$i]->lineTotal;
            }
            foreach (DiscountCalculator::allocate($applied->amount, $weights) as $i => $part) {
                $lineDiscounts[$i] = $part;
            }
        }
        $discountTotal = $applied->amount ?? Money::zero();

        return new PricedCart($priced, $subtotal, $discountTotal, $subtotal->subtract($discountTotal), $applied, array_values($lineDiscounts));
    }

    private function line(Branch $location, ?Product $product, CartLine $line): PricedLine
    {
        if ($product === null) {
            throw new CartLineInvalid(__('This item is not available here.'));
        }

        if (! $line->quantity->isPositive()) {
            throw new CartLineInvalid(__(':item: quantity must be more than zero.', ['item' => $product->name]));
        }

        $variant = $this->variant($product, $line->variantId);

        $unitPrice = $this->prices->current($product, $variant, $location);
        if ($unitPrice === null) {
            throw new CartLineInvalid(__(':item has no price here.', ['item' => $product->name]));
        }

        return new PricedLine(
            product: $product,
            variant: $variant,
            quantity: $line->quantity,
            unitPrice: $unitPrice,
            lineTotal: $unitPrice->multiply($line->quantity),
            notes: $line->notes,
        );
    }

    private function variant(Product $product, ?int $variantId): ?ProductVariant
    {
        $active = $product->variants->where('is_active', true);

        if ($variantId === null) {
            if ($active->isNotEmpty()) {
                throw new CartLineInvalid(__('Choose a size or variant for :item.', ['item' => $product->name]));
            }

            return null;
        }

        $variant = $active->firstWhere('id', $variantId);
        if (! $variant instanceof ProductVariant) {
            throw new CartLineInvalid(__('That variant of :item is not available.', ['item' => $product->name]));
        }

        return $variant;
    }
}
