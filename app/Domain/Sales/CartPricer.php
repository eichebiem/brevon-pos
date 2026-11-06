<?php

namespace App\Domain\Sales;

use App\Domain\Catalog\CatalogQuery;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Discounts\DiscountRequest;
use App\Domain\Shared\Money;
use App\Domain\Tax\TaxCalculator;
use App\Enums\OptionSelectionType;
use App\Models\Branch;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validates a cart against the current catalog and prices it on the server
 * (FR-026, FR-027, BR-003): the product must be sellable at the location
 * (active, on the POS, in an active category, enabled here, finished stock
 * at a pop-up), the variant and options must be valid and active, and the
 * price is the one in force now. Line total = quantity × (unit price +
 * options), rounded half-up to the centavo (D-034). A discount and any
 * tax rules in effect are applied after (D-147, D-149). Errors are keyed
 * `items.{index}` so the POS can point at the line.
 */
class CartPricer
{
    public const MAX_LINES = 100;

    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly PriceResolver $prices,
        private readonly DiscountCalculator $discounts,
        private readonly TaxCalculator $taxes,
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
            ->with(['variants', 'optionGroups' => fn ($q) => $q->where('is_active', true), 'optionGroups.options'])
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

        // Tax on what is left after the discount (D-149), spread the same way.
        $net = [];
        foreach ($priced as $i => $line) {
            $net[$i] = $line->lineTotal->subtract($lineDiscounts[$i]);
        }
        $taxes = $this->taxes->apply($subtotal->subtract($discountTotal));
        $lineTaxes = array_fill(0, count($priced), Money::zero());
        $taxTotal = Money::zero();
        $added = Money::zero();
        foreach ($taxes as $tax) {
            foreach (DiscountCalculator::allocate($tax->amount, $net) as $i => $part) {
                $lineTaxes[$i] = $lineTaxes[$i]->add($part);
            }
            $taxTotal = $taxTotal->add($tax->amount);
            if (! $tax->rule->inclusive) {
                $added = $added->add($tax->amount);
            }
        }

        return new PricedCart($priced, $subtotal, $discountTotal, $taxTotal, $subtotal->subtract($discountTotal)->add($added), $applied, array_values($lineDiscounts), array_values($lineTaxes), $taxes);
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

        $options = $this->options($product, $line->optionIds);
        $optionTotal = Money::sum(array_map(fn (array $o) => Money::fromDecimal($o['option']->price_delta), $options));

        return new PricedLine(
            product: $product,
            variant: $variant,
            quantity: $line->quantity,
            unitPrice: $unitPrice,
            options: $options,
            optionTotal: $optionTotal,
            lineTotal: $unitPrice->add($optionTotal)->multiply($line->quantity),
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

    /**
     * @param  list<int>  $optionIds
     * @return list<array{group: OptionGroup, option: ProductOption}>
     */
    private function options(Product $product, array $optionIds): array
    {
        if (count($optionIds) !== count(array_unique($optionIds))) {
            throw new CartLineInvalid(__('An option was chosen twice for :item.', ['item' => $product->name]));
        }

        $chosen = [];
        $remaining = array_flip($optionIds);

        foreach ($product->optionGroups as $group) {
            $picked = $group->options->filter(fn (ProductOption $o) => isset($remaining[$o->id]));

            if ($picked->contains(fn (ProductOption $o) => ! $o->is_active)) {
                throw new CartLineInvalid(__('An option chosen for :item is no longer available.', ['item' => $product->name]));
            }

            $max = $group->selection_type === OptionSelectionType::Single ? 1 : $group->max_selections;
            if ($picked->count() < $group->min_selections) {
                throw new CartLineInvalid(__('Choose :group for :item.', ['group' => $group->name, 'item' => $product->name]));
            }
            if ($max !== null && $picked->count() > $max) {
                throw new CartLineInvalid(__('Too many choices for :group on :item.', ['group' => $group->name, 'item' => $product->name]));
            }

            foreach ($picked as $option) {
                unset($remaining[$option->id]);
                $chosen[] = ['group' => $group, 'option' => $option];
            }
        }

        if ($remaining !== []) {
            throw new CartLineInvalid(__('An option chosen for :item does not apply to it.', ['item' => $product->name]));
        }

        return $chosen;
    }
}
