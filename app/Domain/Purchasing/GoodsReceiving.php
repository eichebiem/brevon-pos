<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\QuantityInput;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReceiptCondition;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receiving deliveries (FR-056, FR-059, UC-006; D-137).
 *
 * One transaction per delivery: the goods receipt, a purchase_receipt
 * movement for each accepted line at the delivered
 * cost (which updates the weighted average, D-048), and — against a
 * purchase order — the received quantities and the order's status.
 * Damaged or expired quantities are recorded on the receipt but never
 * added to stock. Taking more than was ordered needs someone who can
 * approve purchase orders to accept it, with a note.
 */
class GoodsReceiving
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly NumberSequence $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{location: Branch, purchase_order: PurchaseOrder|null, supplier_id: int|null, delivery_reference: string|null, notes: string|null, accept_over: bool}  $header
     * @param  list<array{inventory_item_id: int, purchase_order_item_id: int|null, quantity: string|null, unit_cost: string|null, rejected_quantity: string|null, rejected_condition: string|null}>  $lines
     */
    public function receive(User $actor, array $header, array $lines): GoodsReceipt
    {
        return DB::transaction(function () use ($actor, $header, $lines) {
            $order = $header['purchase_order'];
            if ($order !== null) {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! $order->status->isOpen()) {
                    throw ValidationException::withMessages(['order' => __('Order :number is :status; nothing more can be received against it.', ['number' => $order->po_number, 'status' => strtolower($order->status->label())])]);
                }
                $location = Branch::query()->findOrFail($order->branch_id);
                $supplierId = $order->supplier_id;
            } else {
                $location = $header['location'];
                $supplierId = $header['supplier_id'];
                if ($supplierId !== null && ! Supplier::query()->whereKey($supplierId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['supplier_id' => __('Choose an active supplier, or leave it empty.')]);
                }
            }
            PurchaseItems::assertReceivingLocation($location);

            $orderLines = $order?->items()->lockForUpdate()->get()->keyBy('id');
            $rows = $this->parseLines($lines, $orderLines, $header['accept_over']);
            $over = in_array(true, array_column($rows, 'over'), true);
            if ($over) {
                if (! $actor->can('purchase-orders.approve')) {
                    throw ValidationException::withMessages(['accept_over' => __('More than ordered was delivered. A manager must accept the extra.')]);
                }
                if (trim((string) $header['notes']) === '') {
                    throw ValidationException::withMessages(['notes' => __('Explain why more than ordered was accepted.')]);
                }
            }

            $today = BusinessClock::today();
            $number = sprintf('%s-%s-GR%02d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'goods_receipt', $today));
            $receipt = new GoodsReceipt;
            $receipt->forceFill([
                'branch_id' => $location->id,
                'purchase_order_id' => $order?->id,
                'supplier_id' => $supplierId,
                'receipt_number' => $number,
                'supplier_delivery_reference' => $header['delivery_reference'],
                'received_at' => now(),
                'received_by' => $actor->id,
                'over_receipt_approved_by' => $over ? $actor->id : null,
                'notes' => $header['notes'],
            ])->save();

            $changes = [];
            foreach ($rows as $row) {
                $item = $row['item'];
                if ($row['quantity'] !== null) {
                    (new GoodsReceiptItem)->forceFill([
                        'goods_receipt_id' => $receipt->id,
                        'purchase_order_item_id' => $row['order_line']?->id,
                        'inventory_item_id' => $item->id,
                        'quantity_received' => $row['quantity']->toString(),
                        'unit_cost' => $row['unit_cost'],
                        'condition' => ReceiptCondition::Accepted,
                    ])->save();
                    $changes[] = new StockChange($location->id, $item->id, InventoryMovementType::PurchaseReceipt, $row['quantity'], $row['unit_cost'], $number, $receipt);

                    if ($row['order_line'] !== null) {
                        $line = $row['order_line'];
                        $line->quantity_received = Quantity::fromString($line->quantity_received)->add($row['quantity'])->toString();
                        $line->save();
                    }
                }
                if ($row['rejected_quantity'] !== null && $row['rejected_condition'] !== null) {
                    (new GoodsReceiptItem)->forceFill([
                        'goods_receipt_id' => $receipt->id,
                        'purchase_order_item_id' => $row['order_line']?->id,
                        'inventory_item_id' => $item->id,
                        'quantity_received' => $row['rejected_quantity']->toString(),
                        'unit_cost' => $row['unit_cost'],
                        'condition' => $row['rejected_condition'],
                    ])->save();
                }
            }

            $this->ledger->post($actor, $changes);

            $statusBefore = $order?->status->value;
            if ($order !== null && $orderLines !== null) {
                $complete = $orderLines->every(fn (PurchaseOrderItem $l) => Quantity::fromString($l->quantity_received)->compare(Quantity::fromString($l->quantity_ordered)) >= 0);
                $order->forceFill(['status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived])->save();
            }

            $this->audit->record('goods_receipt.received', $receipt, $order ? ['order_status' => $statusBefore] : null, [
                'number' => $number,
                'purchase_order' => $order?->po_number,
                'order_status' => $order?->status->value,
                'accepted_lines' => count($changes),
                'over_receipt' => $over,
            ], $location->id, $actor);

            return $receipt;
        });
    }

    /**
     * @param  list<array{inventory_item_id: int, purchase_order_item_id: int|null, quantity: string|null, unit_cost: string|null, rejected_quantity: string|null, rejected_condition: string|null}>  $lines
     * @param  Collection<int, PurchaseOrderItem>|null  $orderLines
     * @return list<array{item: InventoryItem, order_line: PurchaseOrderItem|null, quantity: Quantity|null, unit_cost: numeric-string|null, rejected_quantity: Quantity|null, rejected_condition: ReceiptCondition|null, over: bool}>
     */
    private function parseLines(array $lines, $orderLines, bool $acceptOver): array
    {
        $blank = fn (?string $v) => trim((string) $v) === '';
        $lines = array_filter($lines, fn (array $l) => ! $blank($l['quantity']) || ! $blank($l['rejected_quantity']));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Enter what was delivered for at least one item.')]);
        }

        $items = PurchaseItems::query()->with('unit')->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $id = (int) $line['inventory_item_id'];
            $orderLine = null;
            if ($orderLines !== null) {
                $orderLine = $orderLines->get((int) $line['purchase_order_item_id']);
                if ($orderLine === null || $orderLine->inventory_item_id !== $id) {
                    $errors["lines.{$i}.quantity"] = __('This item is not on the order.');

                    continue;
                }
            }
            /** @var InventoryItem|null $item */
            $item = $items->get($id);
            if ($item === null || isset($seen[$id])) {
                $errors["lines.{$i}.quantity"] = $item === null ? __('This item cannot be received.') : __('Each item can only be listed once.');

                continue;
            }
            $seen[$id] = true;
            $precision = $item->unit->precision ?? 3;
            $unit = $item->unit?->code;

            $quantity = null;
            if (! $blank($line['quantity'])) {
                $quantity = QuantityInput::parse((string) $line['quantity'], $precision);
                if ($quantity === null) {
                    $errors["lines.{$i}.quantity"] = QuantityInput::message($precision);

                    continue;
                }
            }

            $rejected = null;
            $condition = null;
            if (! $blank($line['rejected_quantity'])) {
                $rejected = QuantityInput::parse((string) $line['rejected_quantity'], $precision);
                $condition = ReceiptCondition::tryFrom((string) $line['rejected_condition']);
                if ($rejected === null) {
                    $errors["lines.{$i}.rejected_quantity"] = QuantityInput::message($precision);

                    continue;
                }
                if ($condition === null || $condition === ReceiptCondition::Accepted) {
                    $errors["lines.{$i}.rejected_condition"] = __('Say whether it was damaged or expired.');

                    continue;
                }
            }

            $cost = $blank($line['unit_cost']) ? null : PurchaseItems::parseCost($line['unit_cost']);
            if ($quantity !== null && $cost === null) {
                $errors["lines.{$i}.unit_cost"] = __('Enter the cost per :unit on the delivery (0 or more, up to 4 decimal places).', ['unit' => $unit]);

                continue;
            }

            $over = false;
            if ($orderLine !== null && $quantity !== null) {
                $after = Quantity::fromString($orderLine->quantity_received)->add($quantity);
                $ordered = Quantity::fromString($orderLine->quantity_ordered);
                if ($after->compare($ordered) > 0) {
                    $left = $ordered->subtract(Quantity::fromString($orderLine->quantity_received));
                    if (! $acceptOver) {
                        $errors["lines.{$i}.quantity"] = __('More than ordered: only :left :unit is still due. Tick "Accept more than ordered" to take the extra.', ['left' => $left->isNegative() ? '0' : self::plain($left), 'unit' => $unit]);

                        continue;
                    }
                    $over = true;
                }
            }

            $rows[] = [
                'item' => $item,
                'order_line' => $orderLine,
                'quantity' => $quantity,
                'unit_cost' => $cost,
                'rejected_quantity' => $rejected,
                'rejected_condition' => $condition,
                'over' => $over,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /** "1000.000" → "1,000", "2.500" → "2.5" for messages. */
    private static function plain(Quantity $quantity): string
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity->toString(), 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return number_format((int) $whole).($fraction !== '' ? '.'.$fraction : '');
    }
}
