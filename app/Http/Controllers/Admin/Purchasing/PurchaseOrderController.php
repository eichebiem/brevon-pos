<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Domain\Inventory\StockDispatch;
use App\Domain\Purchasing\PurchaseItems;
use App\Domain\Purchasing\PurchaseOrders;
use App\Domain\Shared\Quantity;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Purchase orders (FR-059, UC-006; D-136). Kept at the main branch. */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrders $orders,
        private readonly StockDispatch $dispatch,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'supplier' => ['nullable', 'integer'],
        ]);
        $locationIds = $user->branches()->pluck('branches.id');

        return Inertia::render('admin/purchasing/orders/Index', [
            'orders' => PurchaseOrder::query()
                ->whereIn('branch_id', $locationIds)
                ->with(['supplier:id,supplier_code,name', 'creator:id,name'])
                ->withCount('items')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['supplier'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
                ->orderByRaw("status IN ('submitted','partially_received') DESC")->orderByDesc('id')
                ->paginate(25)->withQueryString()
                ->through(fn (PurchaseOrder $o) => $this->row($o)),
            'filters' => (object) $filters,
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'supplier_code', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->assertWorksAtMain($request);

        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $main = $this->assertWorksAtMain($request);
        [$header, $lines] = $this->validated($request);
        $order = $this->orders->create($this->user($request), $main, $header, $lines);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft :number saved.', ['number' => $order->po_number])]);

        return to_route('admin.purchase-orders.show', $order);
    }

    public function show(Request $request, PurchaseOrder $order): Response
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($order->branch_id), 404);
        $order->load(['supplier', 'branch:id,code,name', 'creator:id,name', 'approver:id,name', 'canceller:id,name', 'items.item.unit', 'receipts.receiver:id,name']);
        $draft = $order->status === PurchaseOrderStatus::Draft;

        return Inertia::render('admin/purchasing/orders/Show', [
            'order' => [
                ...$this->row($order),
                'notes' => $order->notes,
                'location' => $order->branch?->only(['code', 'name']),
                'supplier_details' => $order->supplier?->only(['contact_person', 'phone', 'email', 'payment_terms']),
                'approved_by' => $order->approver?->name,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
                'cancelled_by' => $order->canceller?->name,
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'cancel_reason' => $order->cancel_reason,
                'lines' => $order->items->map(fn (PurchaseOrderItem $l) => [
                    'id' => $l->id,
                    'sku' => $l->item?->sku,
                    'name' => $l->item?->name,
                    'unit' => $l->item?->unit?->code,
                    'quantity_ordered' => $l->quantity_ordered,
                    'quantity_received' => $l->quantity_received,
                    'quantity_due' => $this->due($l),
                    'unit_cost' => $l->unit_cost,
                    'line_total' => $l->line_total,
                ])->values(),
                'receipts' => $order->receipts->map(fn (GoodsReceipt $r) => [
                    'id' => $r->id,
                    'number' => $r->receipt_number,
                    'received_at' => $r->received_at->toIso8601String(),
                    'received_by' => $r->receiver?->name,
                    'delivery_reference' => $r->supplier_delivery_reference,
                ])->values(),
            ],
            'can' => [
                'edit' => $draft && $user->can('purchase-orders.create'),
                'submit' => $draft && $user->can('purchase-orders.approve'),
                'cancel' => in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::PartiallyReceived], true)
                    && $user->can($draft ? 'purchase-orders.create' : 'purchase-orders.approve'),
                'receive' => $order->status->isOpen() && $user->can('purchase-orders.receive'),
            ],
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): Response|RedirectResponse
    {
        abort_unless($this->user($request)->worksAt($order->branch_id), 404);
        if ($order->status !== PurchaseOrderStatus::Draft) {
            return to_route('admin.purchase-orders.show', $order);
        }

        return $this->form($order->load('items'));
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($this->user($request)->worksAt($order->branch_id), 404);
        [$header, $lines] = $this->validated($request);
        $this->orders->update($this->user($request), $order, $header, $lines);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft :number saved.', ['number' => $order->po_number])]);

        return to_route('admin.purchase-orders.show', $order);
    }

    public function submit(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($this->user($request)->worksAt($order->branch_id), 404);
        $this->orders->submit($this->user($request), $order);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number approved and marked as ordered.', ['number' => $order->po_number])]);

        return back();
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($this->user($request)->worksAt($order->branch_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->orders->cancel($this->user($request), $order, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :number cancelled.', ['number' => $order->po_number])]);

        return back();
    }

    private function form(?PurchaseOrder $order): Response
    {
        return Inertia::render('admin/purchasing/orders/Form', [
            'order' => $order ? [
                'id' => $order->id,
                'number' => $order->po_number,
                'supplier_id' => $order->supplier_id,
                'expected_at' => $order->expected_at?->toDateString(),
                'notes' => $order->notes,
                'lines' => $order->items->map(fn (PurchaseOrderItem $l) => [
                    'inventory_item_id' => $l->inventory_item_id,
                    'quantity' => Quantity::fromString($l->quantity_ordered)->toString(),
                    'unit_cost' => $l->unit_cost,
                ])->values(),
            ] : null,
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'supplier_code', 'name']),
            'items' => PurchaseItems::forForm(),
        ]);
    }

    /**
     * @return array{0: array{supplier_id: int, expected_at: string|null, notes: string|null}, 1: list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'expected_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
        ], ['supplier_id.required' => __('Choose a supplier.'), 'lines.required' => __('Add at least one item with a quantity.')]);

        return [
            ['supplier_id' => (int) $data['supplier_id'], 'expected_at' => $data['expected_at'] ?? null, 'notes' => $data['notes'] ?? null],
            array_values(array_map(fn (array $l) => [
                'inventory_item_id' => (int) $l['inventory_item_id'],
                'quantity' => $l['quantity'] ?? null,
                'unit_cost' => $l['unit_cost'] ?? null,
            ], $data['lines'])),
        ];
    }

    private function assertWorksAtMain(Request $request): Branch
    {
        $main = $this->dispatch->main();
        abort_unless($this->user($request)->worksAt($main->id), 404);

        return $main;
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
    private function row(PurchaseOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->po_number,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'supplier' => $o->supplier?->only(['id', 'supplier_code', 'name']),
            'expected_at' => $o->expected_at?->toDateString(),
            'subtotal' => $o->subtotal,
            'created_by' => $o->creator?->name,
            'created_at' => $o->created_at->toIso8601String(),
            'items_count' => $o->items_count ?? $o->items->count(),
        ];
    }
}
