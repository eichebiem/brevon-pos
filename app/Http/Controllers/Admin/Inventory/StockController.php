<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Inventory\Lots;
use App\Domain\Inventory\OpeningStock;
use App\Domain\Locations\LocationContext;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock on hand and the movement ledger for the selected location
 * (FR-054, FR-055), plus opening-stock entry (D-051, D-093).
 */
class StockController extends Controller
{
    public function __construct(
        private readonly LocationContext $locations,
        private readonly OpeningStock $opening,
    ) {}

    public function index(Request $request): Response
    {
        $location = $this->location($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'low' => ['nullable', 'boolean']]);
        $search = $filters['search'] ?? null;
        $lowOnly = (bool) ($filters['low'] ?? false);

        $rows = $location === null ? collect() : BranchInventory::query()
            ->where('branch_id', $location->id)
            ->join('inventory_items', 'inventory_items.id', '=', 'branch_inventory.inventory_item_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'inventory_items.unit_of_measure_id')
            ->when($search, fn ($q, $s) => $q->where(fn ($q) => $q->where('inventory_items.name', 'like', "%{$s}%")->orWhere('inventory_items.sku', 'like', "%{$s}%")))
            // FR-057: at or below the item's reorder level (0 = no alert).
            ->when($lowOnly, fn ($q) => $q->where('inventory_items.reorder_level', '>', 0)->whereColumn('branch_inventory.quantity_on_hand', '<=', 'inventory_items.reorder_level'))
            ->orderBy('inventory_items.name')
            ->get([
                'inventory_items.id', 'inventory_items.sku', 'inventory_items.name', 'inventory_items.reorder_level',
                'units_of_measure.code AS unit', 'branch_inventory.quantity_on_hand', 'branch_inventory.average_unit_cost',
                'branch_inventory.updated_at',
            ])
            ->map(fn (BranchInventory $row) => [
                'id' => $row->getAttribute('id'),
                'sku' => $row->getAttribute('sku'),
                'name' => $row->getAttribute('name'),
                'unit' => $row->getAttribute('unit'),
                'on_hand' => $row->quantity_on_hand,
                'reorder_level' => $row->getAttribute('reorder_level'),
                'average_unit_cost' => $row->average_unit_cost,
                'low' => self::isLow($row->quantity_on_hand, (string) $row->getAttribute('reorder_level')),
                'updated_at' => $row->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/inventory/Stock', [
            'site' => $location?->only(['id', 'code', 'name']),
            'rows' => $rows,
            'search' => $search,
            'lowOnly' => $lowOnly,
            'lowCount' => $location === null ? 0 : BranchInventory::query()->where('branch_id', $location->id)
                ->join('inventory_items', 'inventory_items.id', '=', 'branch_inventory.inventory_item_id')
                ->where('inventory_items.reorder_level', '>', 0)
                ->whereColumn('branch_inventory.quantity_on_hand', '<=', 'inventory_items.reorder_level')->count(),
            'lots' => $location ? app(Lots::class)->counts($location) : ['expiring' => 0, 'expired' => 0],
            'awaitingOpening' => $location ? $this->opening->eligibleItems($location)->count() : 0,
        ]);
    }

    public function movements(Request $request): Response
    {
        $location = $this->location($request);
        $filters = $request->validate([
            'item' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:'.implode(',', InventoryMovementType::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $zone = config('brevon.business_timezone');

        $movements = InventoryMovement::query()
            ->where('branch_id', $location->id ?? 0)
            ->with(['item:id,sku,name', 'creator:id,name'])
            ->when($filters['item'] ?? null, fn ($q, $id) => $q->where('inventory_item_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('movement_type', $type))
            // Manila business days converted to UTC ranges (D-031).
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', CarbonImmutable::parse($d, $zone)->addDay()->startOfDay()->utc()))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'created_at' => $m->created_at->toIso8601String(),
                'sku' => $m->item?->sku,
                'item' => $m->item?->name,
                'type' => $m->movement_type->value,
                'type_label' => $m->movement_type->label(),
                'quantity' => $m->quantity_delta,
                'balance_after' => $m->balance_after,
                'reason' => $m->reason,
                'by' => $m->creator?->name,
            ]);

        return Inertia::render('admin/inventory/Movements', [
            'site' => $location?->only(['id', 'code', 'name']),
            'movements' => $movements,
            'filters' => (object) $filters,
            'items' => InventoryItem::query()->orderBy('name')->get(['id', 'sku', 'name']),
            'types' => collect(InventoryMovementType::cases())
                ->map(fn (InventoryMovementType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
        ]);
    }

    public function openingForm(Request $request): Response
    {
        $location = $this->location($request);

        return Inertia::render('admin/inventory/OpeningStock', [
            'site' => $location?->only(['id', 'code', 'name']),
            'isPopUp' => $location !== null && ! $location->isPermanent(),
            'items' => $location === null ? [] : $this->opening->eligibleItems($location)->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit?->code,
                'precision' => $item->unit->precision ?? 3,
            ])->values(),
        ]);
    }

    public function storeOpening(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
        ]);

        $location = $this->location($request);

        if ($location === null) {
            abort(403, __('You have no operating location selected.'));
        }

        /** @var User $user */
        $user = $request->user();
        $count = $this->opening->post($user, $location, array_values(array_map(fn (array $line) => [
            'inventory_item_id' => (int) $line['inventory_item_id'],
            'quantity' => $line['quantity'] ?? null,
            'unit_cost' => $line['unit_cost'] ?? null,
        ], $data['lines'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Opening stock saved for :count item.|Opening stock saved for :count items.', $count, ['count' => $count])]);

        return to_route('admin.inventory.stock');
    }

    /** FR-057: at or below a reorder level above zero. */
    private static function isLow(string $onHand, string $reorderLevel): bool
    {
        $level = Quantity::fromString($reorderLevel);

        return $level->isPositive() && Quantity::fromString($onHand)->compare($level) <= 0;
    }

    private function location(Request $request): ?Branch
    {
        /** @var User $user */
        $user = $request->user();

        return $this->locations->current($user);
    }
}
