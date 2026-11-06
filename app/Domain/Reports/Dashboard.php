<?php

namespace App\Domain\Reports;

use App\Models\BranchInventory;

/**
 * The owner/admin dashboard (FR-088): sales figures for the chosen dates and
 * locations, net sales per day, the best sellers, and how many stock items
 * are at or below their reorder level right now.
 */
class Dashboard
{
    public function __construct(private readonly SalesReport $sales) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportFilters $f): array
    {
        $daily = new ReportFilters($f->from, $f->to, $f->branchIds, groupBy: 'day', location: $f->location);

        return [
            'figures' => $this->sales->summary($f)->toArray(),
            'days' => array_map(fn (array $r) => ['start' => $r['start'], 'net' => $r['net'], 'orders' => $r['orders']], $this->sales->byPeriod($daily)),
            'top_products' => array_slice($this->sales->byProduct($f), 0, 5),
            'low_stock' => BranchInventory::query()
                ->whereIn('branch_id', $f->branchIds === [] ? [0] : $f->branchIds)
                ->join('inventory_items', 'inventory_items.id', '=', 'branch_inventory.inventory_item_id')
                ->where('inventory_items.reorder_level', '>', 0)
                ->whereColumn('branch_inventory.quantity_on_hand', '<=', 'inventory_items.reorder_level')
                ->count(),
        ];
    }
}
