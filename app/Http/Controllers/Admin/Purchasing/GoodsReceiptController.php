<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Domain\Inventory\StockDispatch;
use App\Domain\Purchasing\GoodsReceiving;
use App\Domain\Purchasing\PurchaseItems;
use App\Domain\Shared\Quantity;
use App\Enums\ReceiptCondition;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receiving deliveries (UC-006; D-137): against a purchase order
 * (purchase-orders.receive) or directly, e.g. a market run
 * (inventory.receive). Always at the main branch.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiving $receiving,
        private readonly StockDispatch $dispatch,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('admin/purchasing/receipts/Index', [
            'receipts' => GoodsReceipt::query()
                ->whereIn('branch_id', $user->branches()->pluck('branches.id'))
                ->with(['supplier:id,supplier_code,name', 'purchaseOrder:id,po_number', 'receiver:id,name'])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(25)->withQueryString()
                ->through(fn (GoodsReceipt $r) => $this->row($r)),
            'canReceiveDirect' => $user->can('inventory.receive'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate(['order' => ['nullable', 'integer']]);
        $order = isset($data['order']) ? PurchaseOrder::query()->findOrFail((int) $data['order']) : null;
        $this->authorizeReceiving($user, $order);
        $order?->load(['supplier:id,supplier_code,name', 'items.item.unit']);

        return Inertia::render('admin/purchasing/receipts/Create', [
            'order' => $order ? [
                'id' => $order->id,
                'number' => $order->po_number,
                'status_label' => $order->status->label(),
                'is_open' => $order->status->isOpen(),
                'supplier' => $order->supplier?->only(['supplier_code', 'name']),
                'lines' => $order->items->map(fn (PurchaseOrderItem $l) => [
                    'purchase_order_item_id' => $l->id,
                    'inventory_item_id' => $l->inventory_item_id,
                    'sku' => $l->item?->sku,
                    'name' => $l->item?->name,
                    'unit' => $l->item?->unit?->code,
                    'precision' => $l->item->unit->precision ?? 3,
                    'quantity_ordered' => $l->quantity_ordered,
                    'quantity_received' => $l->quantity_received,
                    'quantity_due' => $this->due($l),
                    'unit_cost' => $l->unit_cost,
                ])->values(),
            ] : null,
            'items' => $order ? [] : PurchaseItems::forForm(),
            'suppliers' => $order ? [] : Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'supplier_code', 'name']),
            'canAcceptOver' => $user->can('purchase-orders.approve'),
            'conditions' => collect([ReceiptCondition::Damaged, ReceiptCondition::Expired])->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'accept_over' => ['boolean'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.purchase_order_item_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
            'lines.*.rejected_quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.rejected_condition' => ['nullable', 'string', 'max:20'],
        ], ['lines.required' => __('Enter what was delivered for at least one item.')]);

        $order = isset($data['purchase_order_id']) ? PurchaseOrder::query()->findOrFail((int) $data['purchase_order_id']) : null;
        $this->authorizeReceiving($user, $order);

        $receipt = $this->receiving->receive($user, [
            'location' => $this->dispatch->main(),
            'purchase_order' => $order,
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'delivery_reference' => $data['delivery_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'accept_over' => (bool) ($data['accept_over'] ?? false),
        ], array_values(array_map(fn (array $l) => [
            'inventory_item_id' => (int) $l['inventory_item_id'],
            'purchase_order_item_id' => isset($l['purchase_order_item_id']) ? (int) $l['purchase_order_item_id'] : null,
            'quantity' => $l['quantity'] ?? null,
            'unit_cost' => $l['unit_cost'] ?? null,
            'rejected_quantity' => $l['rejected_quantity'] ?? null,
            'rejected_condition' => $l['rejected_condition'] ?? null,
        ], $data['lines'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery :number received.', ['number' => $receipt->receipt_number])]);

        return to_route('admin.receipts.show', $receipt);
    }

    public function show(Request $request, GoodsReceipt $receipt): Response
    {
        abort_unless($this->user($request)->worksAt($receipt->branch_id), 404);
        $receipt->load(['supplier:id,supplier_code,name', 'purchaseOrder:id,po_number', 'receiver:id,name', 'overReceiptApprover:id,name', 'branch:id,code,name', 'items.item.unit']);

        return Inertia::render('admin/purchasing/receipts/Show', [
            'receipt' => [
                ...$this->row($receipt),
                'location' => $receipt->branch?->only(['code', 'name']),
                'notes' => $receipt->notes,
                'over_receipt_approved_by' => $receipt->overReceiptApprover?->name,
                'lines' => $receipt->items->map(fn (GoodsReceiptItem $l) => [
                    'id' => $l->id,
                    'sku' => $l->item?->sku,
                    'name' => $l->item?->name,
                    'unit' => $l->item?->unit?->code,
                    'quantity' => $l->quantity_received,
                    'unit_cost' => $l->unit_cost,
                    'condition' => $l->condition->value,
                    'condition_label' => $l->condition->label(),
                ])->values(),
            ],
        ]);
    }

    private function authorizeReceiving(User $user, ?PurchaseOrder $order): void
    {
        if ($order !== null) {
            abort_unless($user->worksAt($order->branch_id), 404);
            abort_unless($user->can('purchase-orders.receive'), 403);

            return;
        }
        abort_unless($user->can('inventory.receive'), 403);
        abort_unless($user->worksAt($this->dispatch->main()->id), 404);
    }

    private function due(PurchaseOrderItem $line): string
    {
        $due = Quantity::fromString($line->quantity_ordered)->subtract(Quantity::fromString($line->quantity_received));

        return $due->isNegative() ? '0.000' : $due->toString();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GoodsReceipt $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->receipt_number,
            'purchase_order' => $r->purchaseOrder?->only(['id', 'po_number']),
            'supplier' => $r->supplier?->only(['supplier_code', 'name']),
            'delivery_reference' => $r->supplier_delivery_reference,
            'received_at' => $r->received_at->toIso8601String(),
            'received_by' => $r->receiver?->name,
            'items_count' => $r->items_count ?? $r->items->count(),
        ];
    }
}
