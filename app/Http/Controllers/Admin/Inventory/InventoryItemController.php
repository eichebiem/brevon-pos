<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Inventory\InventoryItemService;
use App\Enums\InventoryItemType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Stock items (FR-057, D-134). Viewing needs inventory.view; changes inventory.adjust. */
class InventoryItemController extends Controller
{
    public function __construct(private readonly InventoryItemService $items) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(InventoryItemType::class)],
        ]);

        return Inertia::render('admin/inventory/items/Index', [
            'items' => InventoryItem::query()
                ->with(['unit:id,code', 'product:id,inventory_item_id,name'])
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
                ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('item_type', $t))
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (InventoryItem $item) => [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'type' => $item->item_type->value,
                    'unit' => $item->unit?->code,
                    'reorder_level' => $item->reorder_level,
                    'is_perishable' => $item->is_perishable,
                    'track_lot' => $item->track_lot,
                    'is_active' => $item->is_active,
                    'product' => $item->product?->name,
                ]),
            'filters' => (object) $filters,
            'types' => self::types(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/inventory/items/Form', [
            'item' => null,
            'linked' => false,
            'hasHistory' => false,
            'units' => self::units(),
            'types' => array_values(array_filter(self::types(), fn (array $t) => $t['value'] !== InventoryItemType::FinishedGood->value)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/', Rule::unique('inventory_items', 'sku')],
            'name' => ['required', 'string', 'max:150'],
            'item_type' => ['required', Rule::in([InventoryItemType::Ingredient->value, InventoryItemType::Packaging->value, InventoryItemType::Retail->value])],
            'unit_of_measure_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'reorder_level' => ['required', 'string', 'max:20'],
            'is_perishable' => ['boolean'],
            'track_lot' => ['boolean'],
        ], ['sku.regex' => __('Use letters, numbers and dashes only.')]);

        /** @var User $actor */
        $actor = $request->user();
        $item = $this->items->create($actor, [
            'sku' => (string) $data['sku'],
            'name' => (string) $data['name'],
            'item_type' => (string) $data['item_type'],
            'unit_of_measure_id' => (int) $data['unit_of_measure_id'],
            'reorder_level' => (string) $data['reorder_level'],
            'is_perishable' => (bool) ($data['is_perishable'] ?? false),
            'track_lot' => (bool) ($data['track_lot'] ?? false),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock item :sku added.', ['sku' => $item->sku])]);

        return to_route('admin.inventory.items.index');
    }

    public function edit(InventoryItem $item): Response
    {
        $item->load('product:id,inventory_item_id,name');

        return Inertia::render('admin/inventory/items/Form', [
            'item' => [
                ...$item->only(['id', 'sku', 'name', 'unit_of_measure_id', 'reorder_level', 'is_perishable', 'track_lot', 'is_active']),
                'item_type' => $item->item_type->value,
                'product' => $item->product?->name,
            ],
            'linked' => $item->product !== null,
            'hasHistory' => InventoryMovement::query()->where('inventory_item_id', $item->id)->exists(),
            'units' => self::units(),
            'types' => self::types(),
        ]);
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'unit_of_measure_id' => ['sometimes', 'required', 'integer', 'exists:units_of_measure,id'],
            'reorder_level' => ['required', 'string', 'max:20'],
            'is_perishable' => ['boolean'],
            'track_lot' => ['boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->items->update($actor, $item, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock item :sku saved.', ['sku' => $item->sku])]);

        return to_route('admin.inventory.items.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function types(): array
    {
        return array_map(fn (InventoryItemType $t) => ['value' => $t->value, 'label' => ucfirst(str_replace('_', ' ', $t->value))], InventoryItemType::cases());
    }

    /**
     * @return list<array{id: int, code: string, name: string, precision: int}>
     */
    private static function units(): array
    {
        return array_values(UnitOfMeasure::query()->orderBy('name')->get(['id', 'code', 'name', 'precision'])
            ->map(fn (UnitOfMeasure $u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'precision' => $u->precision])->all());
    }
}
