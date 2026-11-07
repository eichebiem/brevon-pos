<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Locations\LocationContext;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Models\BranchInventory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock valuation at weighted average cost (FR-063; D-048, D-139).
 */
class StockValueController extends Controller
{
    public function __construct(private readonly LocationContext $locations) {}

    public function valuation(Request $request): Response
    {
        $user = $this->user($request);
        $location = $this->locations->current($user);

        $rows = $location === null ? collect() : BranchInventory::query()
            ->where('branch_id', $location->id)
            ->where('quantity_on_hand', '>', 0)
            ->join('inventory_items', 'inventory_items.id', '=', 'branch_inventory.inventory_item_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'inventory_items.unit_of_measure_id')
            ->orderBy('inventory_items.name')
            ->get(['inventory_items.id', 'inventory_items.sku', 'inventory_items.name', 'inventory_items.item_type', 'units_of_measure.code AS unit', 'branch_inventory.quantity_on_hand', 'branch_inventory.average_unit_cost'])
            ->map(fn (BranchInventory $row) => [
                'id' => $row->getAttribute('id'),
                'sku' => $row->getAttribute('sku'),
                'name' => $row->getAttribute('name'),
                'type' => $row->getAttribute('item_type'),
                'unit' => $row->getAttribute('unit'),
                'on_hand' => $row->quantity_on_hand,
                'average_unit_cost' => $row->average_unit_cost,
                'value' => self::value($row->quantity_on_hand, $row->average_unit_cost),
            ])->values();

        $total = Money::sum($rows->pluck('value')->filter()->map(fn (string $v) => Money::fromDecimal($v)))->toDecimal();

        // Every location the user works at, ended pop-ups included.
        $byLocation = DB::table('branch_inventory')
            ->join('branches', 'branches.id', '=', 'branch_inventory.branch_id')
            ->whereIn('branch_inventory.branch_id', $user->branches()->pluck('branches.id'))
            ->where('quantity_on_hand', '>', 0)
            ->groupBy('branches.id', 'branches.code', 'branches.name')
            ->orderBy('branches.code')
            ->get([
                'branches.id', 'branches.code', 'branches.name',
                DB::raw('ROUND(SUM(quantity_on_hand * COALESCE(average_unit_cost, 0)), 2) AS value'),
                DB::raw('SUM(average_unit_cost IS NULL) AS uncosted'),
            ])
            ->map(fn (object $r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'value' => Money::fromDecimal(is_numeric($r->value) ? bcadd((string) $r->value, '0', 2) : '0.00')->toDecimal(), 'uncosted' => (int) $r->uncosted]);

        return Inertia::render('admin/inventory/Valuation', [
            'site' => $location?->only(['id', 'code', 'name']),
            'rows' => $rows,
            'total' => $total,
            'uncosted' => $rows->whereNull('average_unit_cost')->count(),
            'byLocation' => $byLocation,
        ]);
    }

    /**
     * @return numeric-string|null
     */
    private static function value(string $quantity, ?string $cost): ?string
    {
        if ($cost === null || ! is_numeric($cost) || ! is_numeric($quantity)) {
            return null;
        }

        return bcadd(bcmul($quantity, $cost, 7), '0.005', 2);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
