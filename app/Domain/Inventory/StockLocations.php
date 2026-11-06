<?php

namespace App\Domain\Inventory;

use App\Domain\Locations\LocationContext;
use App\Enums\InventoryTrackingMode;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where a user may count, adjust or write off stock, and which items each
 * location holds (D-063, D-132). Besides the locations open today, a
 * pop-up that has ended still appears while it has stock left, so its
 * unsold doughnuts can be written off.
 */
class StockLocations
{
    public function __construct(private readonly LocationContext $context) {}

    /**
     * @return Collection<int, Branch>
     */
    public function forUser(User $user): Collection
    {
        $open = $this->context->available($user);
        $withStock = $user->branches()
            ->whereNotIn('branches.id', $open->pluck('id'))
            ->whereExists(fn ($q) => $q->from('branch_inventory')
                ->whereColumn('branch_inventory.branch_id', 'branches.id')
                ->where('quantity_on_hand', '>', 0))
            ->orderBy('branches.name')
            ->get();

        return $open->concat($withStock)->values();
    }

    public function canUse(User $user, int $branchId): bool
    {
        return $this->forUser($user)->contains('id', $branchId);
    }

    /**
     * Active items that can be stocked at the location: everything at the
     * main branch, only finished-stock doughnuts at a pop-up (D-063).
     *
     * @return Builder<InventoryItem>
     */
    public function itemsQuery(Branch $location): Builder
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->when(! $location->isPermanent(), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p
                ->where('inventory_tracking_mode', InventoryTrackingMode::FinishedStock)));
    }

    /**
     * Items for a stock form, with what the system shows on hand.
     *
     * @return list<array{id: int, sku: string, name: string, unit: string|null, precision: int, on_hand: string}>
     */
    public function formItems(Branch $location): array
    {
        $onHand = BranchInventory::query()->where('branch_id', $location->id)->pluck('quantity_on_hand', 'inventory_item_id');

        return array_values($this->itemsQuery($location)->with('unit')->orderBy('name')->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit?->code,
                'precision' => $item->unit->precision ?? 3,
                'on_hand' => (string) ($onHand[$item->id] ?? '0.000'),
            ])->all());
    }
}
