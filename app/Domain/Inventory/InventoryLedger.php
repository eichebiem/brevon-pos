<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of stock (BR-011, prompt invariant 12; D-051).
 *
 * Each call runs in one transaction: balance rows are locked FOR UPDATE in
 * ascending (location, item) order so concurrent callers cannot deadlock or
 * oversell; negative results are refused unless explicitly allowed (FR-062);
 * every change is written as an append-only movement with the resulting
 * balance, and the balance projection is updated in the same transaction.
 */
class InventoryLedger
{
    /**
     * @param  list<StockChange>  $changes
     * @return list<InventoryMovement>
     */
    public function post(User $actor, array $changes): array
    {
        return $this->write($actor, $changes, allowTransfers: false);
    }

    /**
     * Transfer movements, only for a dispatch document (D-063, D-133): the
     * out and in legs are written in the same transaction.
     *
     * @param  list<StockChange>  $changes
     * @return list<InventoryMovement>
     */
    public function postTransfer(User $actor, array $changes): array
    {
        foreach ($changes as $change) {
            if (! $change->type->isTransfer() || $change->reference?->getMorphClass() !== 'stock_transfer') {
                throw new LogicException('Transfer movements must belong to a dispatch.');
            }
        }

        return $this->write($actor, $changes, allowTransfers: true);
    }

    /**
     * @param  list<StockChange>  $changes
     * @return list<InventoryMovement>
     */
    private function write(User $actor, array $changes, bool $allowTransfers): array
    {
        if ($changes === []) {
            return [];
        }

        foreach ($changes as $change) {
            $this->assertAllowed($change, $allowTransfers);
        }

        return DB::transaction(function () use ($actor, $changes) {
            $balances = $this->lockBalances($changes);
            $allowNegative = (bool) config('brevon.allow_negative_stock');
            $lotTracked = InventoryItem::query()->whereIn('id', array_map(fn (StockChange $c) => $c->inventoryItemId, $changes))
                ->where('track_lot', true)->pluck('id')->flip();
            $movements = [];

            foreach ($changes as $change) {
                $balance = $balances[$this->key($change->branchId, $change->inventoryItemId)];
                $before = Quantity::fromString($balance->quantity_on_hand);
                $delta = $change->type->isInbound() ? $change->quantity : $change->quantity->negate();
                $after = $before->add($delta);

                if ($after->isNegative() && ! $allowNegative) {
                    $name = InventoryItem::query()->whereKey($change->inventoryItemId)->value('name');

                    throw new InsufficientStock($change->branchId, $change->inventoryItemId, (string) $name, $before->toString(), $change->quantity->toString());
                }

                if ($change->type->isInbound() && $change->unitCost !== null) {
                    $balance->average_unit_cost = $this->weightedAverage($before, $balance->average_unit_cost, $change->quantity, $change->unitCost);
                }

                $balance->quantity_on_hand = $after->toString();
                $balance->updated_at = now();
                $balance->save();

                $movement = new InventoryMovement;
                $movement->forceFill([
                    'branch_id' => $change->branchId,
                    'inventory_item_id' => $change->inventoryItemId,
                    'inventory_lot_id' => $change->type->isInbound() ? $change->lotId : null,
                    'movement_type' => $change->type,
                    'quantity_delta' => $delta->toString(),
                    'unit_cost' => $change->unitCost,
                    'balance_after' => $after->toString(),
                    'reference_type' => $change->reference?->getMorphClass(),
                    'reference_id' => $change->reference?->getKey(),
                    'reason' => $change->reason,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ])->save();

                if (! $change->type->isInbound() && $lotTracked->has($change->inventoryItemId)) {
                    $this->useUpLots($change->branchId, $change->inventoryItemId, $change->quantity);
                }

                $movements[] = $movement;
            }

            return $movements;
        }, attempts: 3); // retried on deadlock only when this is the outermost transaction
    }

    /**
     * Locations/items whose balance differs from the sum of their movements.
     * An empty result means the projection reconciles (prompt invariant 12).
     *
     * @return Collection<int, array{branch_id: int, inventory_item_id: int, balance: string, movements: string}>
     */
    public function reconcile(?int $branchId = null): Collection
    {
        $sums = DB::table('inventory_movements')
            ->selectRaw('branch_id, inventory_item_id, SUM(quantity_delta) AS total')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('branch_id', 'inventory_item_id');

        $rows = DB::table('branch_inventory AS b')
            ->leftJoinSub($sums, 'm', fn ($join) => $join->on('m.branch_id', '=', 'b.branch_id')->on('m.inventory_item_id', '=', 'b.inventory_item_id'))
            ->when($branchId, fn ($q) => $q->where('b.branch_id', $branchId))
            ->whereRaw('b.quantity_on_hand <> COALESCE(m.total, 0)')
            ->get(['b.branch_id', 'b.inventory_item_id', 'b.quantity_on_hand AS balance', DB::raw('COALESCE(m.total, 0) AS movements')]);

        // Movements with no balance row at all.
        $orphans = DB::query()->fromSub($sums, 'm')
            ->whereNotExists(fn ($q) => $q->from('branch_inventory AS b')->whereColumn('b.branch_id', 'm.branch_id')->whereColumn('b.inventory_item_id', 'm.inventory_item_id'))
            ->get(['m.branch_id', 'm.inventory_item_id', DB::raw("'0.000' AS balance"), 'm.total AS movements']);

        return $rows->concat($orphans)->map(fn (object $row) => [
            'branch_id' => (int) $row->branch_id,
            'inventory_item_id' => (int) $row->inventory_item_id,
            'balance' => (string) $row->balance,
            'movements' => (string) $row->movements,
        ])->values();
    }

    private function assertAllowed(StockChange $change, bool $allowTransfers): void
    {
        if ($change->type->isTransfer() && ! $allowTransfers) {
            // D-063: stock only moves between locations through a dispatch document.
            throw new LogicException('Stock transfers between locations need a dispatch.');
        }

        if (! $change->quantity->isPositive()) {
            throw new InvalidArgumentException('Stock change quantities must be greater than zero.');
        }

        if ($change->unitCost !== null && (! is_numeric($change->unitCost) || bccomp($change->unitCost, '0', 4) < 0)) {
            throw new InvalidArgumentException('Unit cost must be zero or more.');
        }
    }

    /**
     * Locks every balance row this call touches in a fixed order (deadlock
     * avoidance), creating rows that do not exist yet.
     *
     * @param  list<StockChange>  $changes
     * @return array<string, BranchInventory>
     */
    private function lockBalances(array $changes): array
    {
        $pairs = collect($changes)
            ->map(fn (StockChange $c) => ['branch_id' => $c->branchId, 'inventory_item_id' => $c->inventoryItemId])
            ->unique(fn (array $p) => $this->key($p['branch_id'], $p['inventory_item_id']))
            ->sortBy([['branch_id', 'asc'], ['inventory_item_id', 'asc']])
            ->values();

        $locked = [];

        foreach ($pairs as $pair) {
            // Lock the existing row directly. INSERT IGNORE on an existing row
            // would take a shared lock first, and two callers upgrading shared
            // locks to exclusive deadlock (caught by InventoryConcurrencyTest).
            $balance = BranchInventory::query()->where($pair)->lockForUpdate()->first();

            if ($balance === null) {
                DB::table('branch_inventory')->insertOrIgnore([...$pair, 'quantity_on_hand' => '0.000', 'updated_at' => now()]);
                $balance = BranchInventory::query()->where($pair)->lockForUpdate()->firstOrFail();
            }

            $locked[$this->key($pair['branch_id'], $pair['inventory_item_id'])] = $balance;
        }

        return $locked;
    }

    /**
     * Weighted average cost after receiving stock (D-048). When the balance
     * was zero or negative, or had no cost, the new cost is taken as-is.
     *
     * @return numeric-string
     */
    private function weightedAverage(Quantity $before, ?string $currentAverage, Quantity $received, string $unitCost): string
    {
        if (! is_numeric($unitCost)) {
            throw new InvalidArgumentException('Unit cost must be a decimal number.');
        }

        if (! $before->isPositive() || $currentAverage === null || ! is_numeric($currentAverage)) {
            return bcadd($unitCost, '0', 4);
        }

        $existingValue = bcmul($before->toString(), $currentAverage, 8);
        $receivedValue = bcmul($received->toString(), $unitCost, 8);
        $total = bcadd($before->toString(), $received->toString(), 3);

        // Round half up to 4 places.
        $average = bcdiv(bcadd($existingValue, $receivedValue, 8), $total, 8);

        return bcadd($average, '0.00005', 4);
    }

    /**
     * Lots are used up first-expiring-first as stock leaves (D-138). Stock
     * is never picked by lot at the counter, so this is an estimate that
     * drives the expiry list; lots are locked after the balance row, in the
     * same order everywhere. Stock without a lot (opening stock, counts) is
     * taken last.
     */
    private function useUpLots(int $branchId, int $itemId, Quantity $quantity): void
    {
        $left = $quantity;
        $lots = InventoryLot::query()
            ->where('branch_id', $branchId)->where('inventory_item_id', $itemId)
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if (! $left->isPositive()) {
                break;
            }
            $remaining = Quantity::fromString($lot->quantity_remaining);
            $take = $remaining->compare($left) <= 0 ? $remaining : $left;
            $lot->quantity_remaining = $remaining->subtract($take)->toString();
            $lot->save();
            $left = $left->subtract($take);
        }
    }

    private function key(int $branchId, int $itemId): string
    {
        return $branchId.':'.$itemId;
    }
}
