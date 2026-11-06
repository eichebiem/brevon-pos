<?php

namespace App\Domain\Refunds;

use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RefundItem;

/**
 * The refundable quantity and amount of each line of an order (FR-042,
 * UC-005 steps 48–50; D-151), after every earlier refund. A line's share of
 * the order total is its total after discount, with any added tax spread
 * over the lines the same way; the shares add up to the order total to the
 * centavo.
 */
class Refundables
{
    /**
     * @return list<RefundableLine>
     */
    public function for(Order $order): array
    {
        /** @var list<OrderItem> $items */
        $items = $order->items()->with(['product', 'variant'])->get()->all();
        if ($items === []) {
            return [];
        }

        $weights = [];
        foreach ($items as $i => $item) {
            $weights[$i] = Money::fromDecimal($item->line_total)->subtract(Money::fromDecimal($item->discount_amount));
        }
        $shares = DiscountCalculator::allocate(Money::fromDecimal($order->grand_total), $weights);

        $given = RefundItem::query()
            ->whereIn('order_item_id', array_map(fn (OrderItem $i) => $i->id, $items))
            ->get(['order_item_id', 'quantity', 'amount'])
            ->groupBy('order_item_id');

        // Stock the sale took, per stock item: goods can only go back if
        // this order actually took them from stock.
        $taken = InventoryMovement::query()
            ->where('reference_type', 'order')->where('reference_id', $order->id)
            ->where('movement_type', InventoryMovementType::Sale)
            ->pluck('inventory_item_id')->unique()->all();

        $lines = [];
        foreach ($items as $i => $item) {
            $rows = $given->get($item->id, collect());
            $stockItem = $item->product?->inventory_tracking_mode === InventoryTrackingMode::FinishedStock
                ? ($item->variant->inventory_item_id ?? $item->product->inventory_item_id)
                : null;

            $lines[] = new RefundableLine(
                $item,
                Quantity::fromString($item->quantity),
                $rows->reduce(fn (Quantity $q, RefundItem $r) => $q->add(Quantity::fromString($r->quantity)), Quantity::zero()),
                $shares[$i],
                Money::sum($rows->map(fn (RefundItem $r) => Money::fromDecimal($r->amount))),
                $stockItem !== null && in_array($stockItem, $taken, true) ? $stockItem : null,
            );
        }

        return $lines;
    }
}
