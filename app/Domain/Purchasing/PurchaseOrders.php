<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\QuantityInput;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\NumberSequence;
use App\Enums\PurchaseOrderStatus;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders (FR-059, UC-006; D-136). A draft is edited freely by
 * anyone with purchase-orders.create; sending it to the supplier needs
 * purchase-orders.approve and records who approved it. Once ordered the
 * lines are fixed; deliveries are received against them (GoodsReceiving).
 * An order can be cancelled until it is fully received; quantities already
 * received stay in stock.
 */
class PurchaseOrders
{
    public function __construct(
        private readonly NumberSequence $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id: int, expected_at: string|null, notes: string|null}  $header
     * @param  list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}>  $lines
     */
    public function create(User $actor, Branch $location, array $header, array $lines): PurchaseOrder
    {
        PurchaseItems::assertReceivingLocation($location);
        $supplier = $this->activeSupplier($header['supplier_id']);

        return DB::transaction(function () use ($actor, $location, $header, $lines, $supplier) {
            $rows = $this->parseLines($lines);
            $today = BusinessClock::today();
            $number = sprintf('%s-%s-PO%02d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'purchase_order', $today));

            $order = new PurchaseOrder;
            $order->forceFill([
                'branch_id' => $location->id,
                'supplier_id' => $supplier->id,
                'po_number' => $number,
                'status' => PurchaseOrderStatus::Draft,
                'expected_at' => $header['expected_at'],
                'notes' => $header['notes'],
                'subtotal' => $this->subtotal($rows),
                'created_by' => $actor->id,
            ])->save();
            $this->writeLines($order, $rows);

            $this->audit->record('purchase_order.created', $order, null, [
                'number' => $number, 'supplier' => $supplier->supplier_code, 'lines' => count($rows), 'subtotal' => $order->subtotal,
            ], $location->id, $actor);

            return $order;
        });
    }

    /**
     * @param  array{supplier_id: int, expected_at: string|null, notes: string|null}  $header
     * @param  list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}>  $lines
     */
    public function update(User $actor, PurchaseOrder $order, array $header, array $lines): PurchaseOrder
    {
        $supplier = $this->activeSupplier($header['supplier_id']);

        return DB::transaction(function () use ($actor, $order, $header, $lines, $supplier) {
            $order = $this->lock($order, [PurchaseOrderStatus::Draft], __('Only a draft can be edited.'));
            $rows = $this->parseLines($lines);
            $before = ['supplier_id' => $order->supplier_id, 'subtotal' => $order->subtotal, 'lines' => $order->items()->count()];

            $order->items()->get()->each->delete();
            $order->forceFill([
                'supplier_id' => $supplier->id,
                'expected_at' => $header['expected_at'],
                'notes' => $header['notes'],
                'subtotal' => $this->subtotal($rows),
            ])->save();
            $this->writeLines($order, $rows);

            $this->audit->record('purchase_order.updated', $order, $before, [
                'supplier_id' => $supplier->id, 'subtotal' => $order->subtotal, 'lines' => count($rows),
            ], $order->branch_id, $actor);

            return $order;
        });
    }

    /** Approve the draft and mark it ordered from the supplier (UC-006 step 57). */
    public function submit(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        if (! $actor->can('purchase-orders.approve')) {
            throw ValidationException::withMessages(['order' => __('A manager must approve and send this order.')]);
        }

        return DB::transaction(function () use ($actor, $order) {
            $order = $this->lock($order, [PurchaseOrderStatus::Draft], __('This order was already sent.'));
            if (! Supplier::query()->whereKey($order->supplier_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['order' => __('The supplier is inactive. Choose another supplier first.')]);
            }
            $order->forceFill([
                'status' => PurchaseOrderStatus::Submitted,
                'approved_by' => $actor->id,
                'ordered_at' => now(),
            ])->save();

            $this->audit->record('purchase_order.submitted', $order, ['status' => 'draft'], ['status' => 'submitted'], $order->branch_id, $actor);

            return $order;
        });
    }

    public function cancel(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order, $reason) {
            $order = $this->lock($order, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::PartiallyReceived], __('A received or cancelled order cannot be cancelled.'));
            if ($order->status !== PurchaseOrderStatus::Draft && ! $actor->can('purchase-orders.approve')) {
                throw ValidationException::withMessages(['order' => __('A manager must cancel an order that was already sent.')]);
            }
            $from = $order->status->value;
            $order->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->audit->record('purchase_order.cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'reason' => $reason], $order->branch_id, $actor);

            return $order;
        });
    }

    /**
     * @param  list<PurchaseOrderStatus>  $allowed
     */
    private function lock(PurchaseOrder $order, array $allowed, string $message): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['order' => $message]);
        }

        return $order;
    }

    private function activeSupplier(int $id): Supplier
    {
        $supplier = Supplier::query()->whereKey($id)->where('is_active', true)->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => __('Choose an active supplier.')]);
        }

        return $supplier;
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}>  $lines
     * @return list<array{item: InventoryItem, quantity: numeric-string, unit_cost: numeric-string}>
     */
    private function parseLines(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn (array $l) => trim((string) $l['quantity']) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one item with a quantity.')]);
        }

        $items = PurchaseItems::query()->with('unit')->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $id = (int) $line['inventory_item_id'];
            /** @var InventoryItem|null $item */
            $item = $items->get($id);
            if ($item === null || isset($seen[$id])) {
                $errors["lines.{$i}.quantity"] = $item === null ? __('This item cannot be ordered.') : __('Each item can only be listed once.');

                continue;
            }
            $seen[$id] = true;
            $precision = $item->unit->precision ?? 3;
            $quantity = QuantityInput::parse((string) $line['quantity'], $precision);
            if ($quantity === null) {
                $errors["lines.{$i}.quantity"] = QuantityInput::message($precision);

                continue;
            }
            $cost = PurchaseItems::parseCost($line['unit_cost']);
            if ($cost === null) {
                $errors["lines.{$i}.unit_cost"] = __('Enter the expected cost per :unit (0 or more, up to 4 decimal places).', ['unit' => $item->unit?->code]);

                continue;
            }
            /** @var numeric-string $qty */
            $qty = $quantity->toString();
            $rows[] = ['item' => $item, 'quantity' => $qty, 'unit_cost' => $cost];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @param  list<array{item: InventoryItem, quantity: numeric-string, unit_cost: numeric-string}>  $rows
     */
    private function subtotal(array $rows): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, PurchaseItems::lineTotal($row['quantity'], $row['unit_cost']), 2);
        }

        return $total;
    }

    /**
     * @param  list<array{item: InventoryItem, quantity: numeric-string, unit_cost: numeric-string}>  $rows
     */
    private function writeLines(PurchaseOrder $order, array $rows): void
    {
        foreach ($rows as $row) {
            (new PurchaseOrderItem)->forceFill([
                'purchase_order_id' => $order->id,
                'inventory_item_id' => $row['item']->id,
                'quantity_ordered' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
                'line_total' => PurchaseItems::lineTotal($row['quantity'], $row['unit_cost']),
            ])->save();
        }
    }
}
