<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\NumberSequence;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Doughnut dispatch from the main branch to a pop-up (D-063, D-133).
 * One transaction: a transfer_out at main and a transfer_in at the pop-up
 * for every line, at main's average cost. Posted immediately; there is no
 * receive confirmation in V1 (OI-018 deferred, FE-01).
 */
class StockDispatch
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly NumberSequence $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function main(): Branch
    {
        return Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();
    }

    /**
     * Pop-ups the user works at that are active and not past their end date
     * (stock may go out the day before a pop-up opens).
     *
     * @return Collection<int, Branch>
     */
    public function destinations(User $user): Collection
    {
        $today = BusinessClock::today()->toDateString();

        return $user->branches()
            ->where('branches.location_type', LocationType::PopUp)
            ->where('branches.is_active', true)
            ->where(fn ($q) => $q->whereNull('branches.operating_ends_on')->orWhere('branches.operating_ends_on', '>=', $today))
            ->orderBy('branches.name')
            ->get();
    }

    /**
     * Doughnuts a pop-up can receive: finished goods of finished-stock products.
     *
     * @return Builder<InventoryItem>
     */
    public function itemsQuery(): Builder
    {
        return InventoryItem::query()->where('is_active', true)
            ->whereHas('product', fn (Builder $p) => $p->where('inventory_tracking_mode', InventoryTrackingMode::FinishedStock));
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null}>  $lines
     */
    public function dispatch(User $actor, Branch $to, array $lines, ?string $notes): StockTransfer
    {
        $from = $this->main();
        if (! $actor->worksAt($from->id) || ! $this->destinations($actor)->contains('id', $to->id)) {
            throw ValidationException::withMessages(['to_branch_id' => __('Choose an active pop-up you are assigned to.')]);
        }

        $lines = array_values(array_filter($lines, fn (array $l) => trim((string) $l['quantity']) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Enter a quantity for at least one item.')]);
        }

        return DB::transaction(function () use ($actor, $from, $to, $lines, $notes) {
            $items = $this->itemsQuery()->with('unit')->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');
            $parsed = [];
            $errors = [];
            foreach ($lines as $i => $line) {
                $id = (int) $line['inventory_item_id'];
                /** @var InventoryItem|null $item */
                $item = $items->get($id);
                if ($item === null || isset($parsed[$id])) {
                    $errors["lines.{$i}.quantity"] = $item === null ? __('Only doughnuts kept as stock can be dispatched.') : __('Each item can only be listed once.');

                    continue;
                }
                $precision = $item->unit->precision ?? 0;
                $quantity = QuantityInput::parse((string) $line['quantity'], $precision);
                if ($quantity === null) {
                    $errors["lines.{$i}.quantity"] = QuantityInput::message($precision);

                    continue;
                }
                $parsed[$id] = $quantity;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $today = BusinessClock::today();
            $transfer = new StockTransfer;
            $transfer->forceFill([
                'transfer_number' => sprintf('%s-%s-D%02d', $from->code, $today->format('Ymd'), $this->numbers->next($from, 'dispatch', $today)),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'notes' => $notes,
                'created_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $costs = BranchInventory::query()->where('branch_id', $from->id)->whereIn('inventory_item_id', array_keys($parsed))->pluck('average_unit_cost', 'inventory_item_id');
            $changes = [];
            foreach ($parsed as $id => $quantity) {
                $cost = isset($costs[$id]) ? (string) $costs[$id] : null;
                (new StockTransferItem)->forceFill([
                    'stock_transfer_id' => $transfer->id,
                    'inventory_item_id' => $id,
                    'quantity' => $quantity->toString(),
                    'unit_cost' => $cost,
                ])->save();
                $reason = __('Dispatch :number to :location', ['number' => $transfer->transfer_number, 'location' => $to->name]);
                $changes[] = new StockChange($from->id, $id, InventoryMovementType::TransferOut, $quantity, $cost, $reason, $transfer);
                $changes[] = new StockChange($to->id, $id, InventoryMovementType::TransferIn, $quantity, $cost, $reason, $transfer);
            }

            try {
                $this->ledger->postTransfer($actor, $changes);
            } catch (InsufficientStock $e) {
                throw ValidationException::withMessages(['lines' => __('Only :available of :item is at the main branch, so :requested cannot be dispatched.', [
                    'available' => $e->available, 'item' => $e->itemName, 'requested' => $e->requested,
                ])]);
            }

            $this->audit->record('stock.dispatched', $transfer, null, [
                'number' => $transfer->transfer_number,
                'to' => $to->code,
                'items' => collect($parsed)->map(fn ($q, $id) => ['sku' => $items->get($id)?->sku, 'quantity' => $q->toString()])->values()->all(),
            ], $from->id, $actor);

            return $transfer;
        });
    }
}
