<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Product;
use App\Models\ProductVariant;

/** A cart line validated and priced by the server. */
final readonly class PricedLine
{
    public function __construct(
        public Product $product,
        public ?ProductVariant $variant,
        public Quantity $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        public ?string $notes,
    ) {}

    /** The finished-stock item this line draws from: the variant's own item, else the product's (D-025). */
    public function stockItemId(): ?int
    {
        return $this->variant->inventory_item_id ?? $this->product->inventory_item_id;
    }
}
