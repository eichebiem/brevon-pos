<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Inventory\StockDispatch;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Quantity;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Doughnut dispatch main → pop-up (D-063, D-133). */
class DispatchController extends Controller
{
    public function __construct(private readonly StockDispatch $dispatch) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $locationIds = $user->branches()->pluck('branches.id');

        return Inertia::render('admin/inventory/dispatches/Index', [
            'dispatches' => StockTransfer::query()
                ->where(fn ($q) => $q->whereIn('to_branch_id', $locationIds)->orWhereIn('from_branch_id', $locationIds))
                ->with(['toLocation:id,code,name', 'creator:id,name', 'items'])
                ->orderByDesc('id')
                ->paginate(25)
                ->through(fn (StockTransfer $t) => [
                    'id' => $t->id,
                    'number' => $t->transfer_number,
                    'to' => $t->toLocation?->only(['code', 'name']),
                    'posted_at' => $t->posted_at->toIso8601String(),
                    'by' => $t->creator?->name,
                    'lines' => $t->items->count(),
                    'pieces' => $t->items->reduce(fn (Quantity $sum, StockTransferItem $i) => $sum->add(Quantity::fromString($i->quantity)), Quantity::zero())->toString(),
                ]),
            'canDispatch' => $user->can('inventory.adjust') && $user->worksAt($this->dispatch->main()->id),
        ]);
    }

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $main = $this->dispatch->main();
        $onHand = BranchInventory::query()->where('branch_id', $main->id)->pluck('quantity_on_hand', 'inventory_item_id');

        return Inertia::render('admin/inventory/dispatches/Create', [
            'from' => $main->only(['id', 'code', 'name']),
            'worksAtMain' => $user->worksAt($main->id),
            'destinations' => $this->dispatch->destinations($user)->map(fn (Branch $b) => [
                ...$b->only(['id', 'code', 'name']),
                'status' => $b->operatingStatus(BusinessClock::today()),
            ])->values(),
            'items' => $this->dispatch->itemsQuery()->orderBy('name')->get()->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'on_hand' => (string) ($onHand[$item->id] ?? '0.000'),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
        ]);

        $transfer = $this->dispatch->dispatch(
            $user,
            Branch::query()->findOrFail((int) $data['to_branch_id']),
            array_values(array_map(fn (array $l) => ['inventory_item_id' => (int) $l['inventory_item_id'], 'quantity' => $l['quantity'] ?? null], $data['lines'])),
            $data['notes'] ?? null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dispatch :number sent.', ['number' => $transfer->transfer_number])]);

        return to_route('admin.inventory.dispatches.show', $transfer);
    }

    public function show(Request $request, StockTransfer $dispatch): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->worksAt($dispatch->from_branch_id) || $user->worksAt($dispatch->to_branch_id), 404);
        $dispatch->load(['fromLocation:id,code,name', 'toLocation:id,code,name', 'creator:id,name', 'items.item']);

        return Inertia::render('admin/inventory/dispatches/Show', [
            'dispatch' => [
                'id' => $dispatch->id,
                'number' => $dispatch->transfer_number,
                'from' => $dispatch->fromLocation?->only(['code', 'name']),
                'to' => $dispatch->toLocation?->only(['code', 'name']),
                'posted_at' => $dispatch->posted_at->toIso8601String(),
                'by' => $dispatch->creator?->name,
                'notes' => $dispatch->notes,
                'lines' => $dispatch->items->map(fn (StockTransferItem $i) => [
                    'id' => $i->id,
                    'sku' => $i->item?->sku,
                    'name' => $i->item?->name,
                    'quantity' => $i->quantity,
                ])->values(),
            ],
        ]);
    }
}
