<?php

namespace App\Domain\Sales;

use App\Domain\Settings\StoreSettings;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Payment;

/**
 * Everything printed on an order receipt (FR-033, FR-034), read only from
 * the stored order and its snapshots — never from the live catalog, so a
 * reprint always matches the original sale (BR-015). The title states it is
 * not an official receipt/invoice (D-037, FR-087). The location's own name
 * and address are printed, so pop-up receipts show the pop-up (D-066).
 */
class OrderReceipt
{
    public const TITLE = 'Order Receipt — not an official receipt/invoice';

    public function __construct(private readonly StoreSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Order $order): array
    {
        $order->loadMissing(['branch', 'terminal', 'creator', 'items.options', 'payments.method']);
        /** @var Branch $location */
        $location = $order->branch;

        return [
            'title' => self::TITLE,
            'store' => [
                'name' => $this->settings->get('store.name'),
                'logo_url' => $this->settings->logoUrl(),
                'footer' => $this->settings->get('receipt.footer'),
            ],
            'location' => [
                'code' => $location->code,
                'name' => $location->name,
                'address' => collect([$location->address_line, $location->barangay, $location->city_municipality, $location->province])->filter()->implode(', ') ?: null,
                'phone' => $location->phone,
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_type' => $order->order_type->value,
                'order_type_label' => $order->order_type->label(),
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'created_at' => $order->created_at->toIso8601String(),
                'pickup_at' => $order->pickup_at?->toIso8601String(),
                'notes' => $order->notes,
                'cashier' => $order->creator?->name,
                'terminal' => $order->terminal?->terminal_code,
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'tax_total' => $order->tax_total,
                'grand_total' => $order->grand_total,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'option_total' => $item->option_total,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
                'options' => $item->options->map(fn (OrderItemOption $o) => ['name' => $o->option_name_snapshot, 'price_delta' => $o->price_delta_snapshot])->all(),
            ])->all(),
            'payments' => $order->payments->map(fn (Payment $p) => [
                'method' => $p->method?->name,
                'amount' => $p->amount,
                'amount_tendered' => $p->amount_tendered,
                'change_amount' => $p->change_amount,
                'reference_number' => $p->reference_number,
                'status' => $p->status->value,
            ])->all(),
        ];
    }
}
