<?php

namespace App\Domain\Orders;

use App\Domain\Shared\BusinessClock;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use Illuminate\Support\Collection;

/**
 * The active orders of one location, grouped for the preparation screen
 * (FR-047, FR-048, D-125): New, Preparing, Ready, and Scheduled for advance
 * pickups that are not due yet. Read only from order snapshots.
 */
class PreparationBoard
{
    /** An advance order shows under New this many minutes before pickup. */
    public const LEAD_MINUTES = 60;

    /**
     * @return array{new: list<array<string, mixed>>, preparing: list<array<string, mixed>>, ready: list<array<string, mixed>>, scheduled: list<array<string, mixed>>}
     */
    public function for(Branch $location): array
    {
        $dueBy = BusinessClock::now()->addMinutes(self::LEAD_MINUTES);

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('branch_id', $location->id)
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready])
            ->with(['items.options'])
            ->orderBy('id')
            ->get();

        $scheduled = fn (Order $o) => $o->status === OrderStatus::Confirmed && $o->pickup_at !== null && $o->pickup_at->greaterThan($dueBy);
        // Due advance orders first by pickup time, then walk-ins in arrival order.
        $queue = fn (Collection $c) => array_values($c->sortBy(fn (Order $o) => [$o->pickup_at?->getTimestamp() ?? PHP_INT_MAX, $o->id])->map($this->card(...))->all());

        return [
            'new' => $queue($orders->filter(fn (Order $o) => $o->status === OrderStatus::Confirmed && ! $scheduled($o))),
            'preparing' => $queue($orders->filter(fn (Order $o) => $o->status === OrderStatus::Preparing)),
            'ready' => $queue($orders->filter(fn (Order $o) => $o->status === OrderStatus::Ready)),
            'scheduled' => $queue($orders->filter($scheduled)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Order $order): array
    {
        $next = $order->status->next();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'short_number' => substr($order->order_number, -4),
            'order_type' => $order->order_type->label(),
            'status' => $order->status->value,
            'next_status' => $next?->value,
            'payment_status' => $order->payment_status->value,
            'created_at' => $order->created_at->toIso8601String(),
            'pickup_at' => $order->pickup_at?->toIso8601String(),
            'notes' => $order->notes,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                // Drinks and other made-to-order lines are what the station prepares; the rest is packed.
                'made_to_order' => $item->product_type_snapshot === ProductType::MadeToOrder->value,
                'options' => $item->options->map(fn (OrderItemOption $o) => $o->option_name_snapshot)->all(),
            ])->all(),
        ];
    }
}
