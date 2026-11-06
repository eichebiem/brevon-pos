<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\StockAdjustmentKind;
use App\Enums\StockAdjustmentStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts and adjustments (FR-056, FR-061, BR-012, BR-013; D-131).
 *
 * A count records what is on the shelf; an adjustment adds or removes a
 * known quantity. Either way the line keeps what the system showed when it
 * was entered, and the difference is what gets posted — so sales made while
 * it waits for approval are not undone. Someone allowed to approve posts at
 * once; anyone else waits for an approver, who can never be themselves.
 */
class StockAdjustments
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly NumberSequence $numbers,
        private readonly StockLocations $locations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null}>  $lines  counted quantity (count) or signed change (change)
     */
    public function create(User $actor, Branch $location, StockAdjustmentKind $kind, string $reason, array $lines): StockAdjustment
    {
        $lines = array_values(array_filter($lines, fn (array $l) => trim((string) $l['quantity']) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Enter a quantity for at least one item.')]);
        }

        $adjustment = DB::transaction(function () use ($actor, $location, $kind, $reason, $lines) {
            $items = $this->locations->itemsQuery($location)->with('unit')
                ->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');
            $onHand = BranchInventory::query()->where('branch_id', $location->id)
                ->whereIn('inventory_item_id', $items->keys())->pluck('quantity_on_hand', 'inventory_item_id');

            $rows = [];
            $errors = [];
            $seen = [];
            foreach ($lines as $i => $line) {
                $id = (int) $line['inventory_item_id'];
                /** @var InventoryItem|null $item */
                $item = $items->get($id);
                if ($item === null || isset($seen[$id])) {
                    $errors["lines.{$i}.quantity"] = $item === null ? __('This item is not stocked at :location.', ['location' => $location->name]) : __('Each item can only be listed once.');

                    continue;
                }
                $seen[$id] = true;
                $precision = $item->unit->precision ?? 3;
                $entered = QuantityInput::parse((string) $line['quantity'], $precision, allowZero: $kind === StockAdjustmentKind::Count, signed: $kind === StockAdjustmentKind::Change);
                if ($entered === null) {
                    $errors["lines.{$i}.quantity"] = $kind === StockAdjustmentKind::Count
                        ? QuantityInput::message($precision, allowZero: true)
                        : __('Enter the amount to add, or a minus amount to remove (not 0).');

                    continue;
                }

                $system = Quantity::fromString((string) ($onHand[$id] ?? '0'));
                $counted = $kind === StockAdjustmentKind::Count ? $entered : $system->add($entered);
                if ($counted->isNegative()) {
                    $errors["lines.{$i}.quantity"] = __('Only :on_hand :unit on hand, so that much cannot be removed.', ['on_hand' => $system->toString(), 'unit' => $item->unit?->code]);

                    continue;
                }
                $rows[] = [$item, $system, $counted];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $today = BusinessClock::today();
            $number = sprintf('%s-%s-ADJ%02d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'adjustment', $today));

            $adjustment = new StockAdjustment;
            $adjustment->forceFill([
                'branch_id' => $location->id,
                'adjustment_number' => $number,
                'kind' => $kind,
                'status' => StockAdjustmentStatus::Pending,
                'reason' => $reason,
                'created_by' => $actor->id,
            ])->save();

            foreach ($rows as [$item, $system, $counted]) {
                (new StockAdjustmentItem)->forceFill([
                    'stock_adjustment_id' => $adjustment->id,
                    'inventory_item_id' => $item->id,
                    'system_quantity' => $system->toString(),
                    'counted_quantity' => $counted->toString(),
                    'variance_quantity' => $counted->subtract($system)->toString(),
                ])->save();
            }

            $this->audit->record('stock_adjustment.created', $adjustment, null, [
                'number' => $number,
                'kind' => $kind->value,
                'reason' => $reason,
                'lines' => count($rows),
            ], $location->id, $actor);

            return $adjustment;
        });

        // BR-012: an approver's own adjustment needs no second person.
        if ($actor->can('inventory.approve-adjustment')) {
            return $this->post($actor, $adjustment, null, ownSubmission: true);
        }

        return $adjustment;
    }

    public function approve(User $actor, StockAdjustment $adjustment, ?string $notes): StockAdjustment
    {
        if ($adjustment->created_by === $actor->id) {
            throw ValidationException::withMessages(['adjustment' => __('Someone else must approve your own adjustment.')]);
        }

        return $this->post($actor, $adjustment, $notes, ownSubmission: false);
    }

    public function reject(User $actor, StockAdjustment $adjustment, string $notes): StockAdjustment
    {
        if ($adjustment->created_by === $actor->id) {
            throw ValidationException::withMessages(['adjustment' => __('Someone else must decide your own adjustment.')]);
        }

        return DB::transaction(function () use ($actor, $adjustment, $notes) {
            $adjustment = $this->lockPending($adjustment);
            $adjustment->forceFill([
                'status' => StockAdjustmentStatus::Rejected,
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            $this->audit->record('stock_adjustment.rejected', $adjustment, ['status' => 'pending'], ['status' => 'rejected', 'notes' => $notes], $adjustment->branch_id, $actor);

            return $adjustment;
        });
    }

    private function post(User $actor, StockAdjustment $adjustment, ?string $notes, bool $ownSubmission): StockAdjustment
    {
        return DB::transaction(function () use ($actor, $adjustment, $notes, $ownSubmission) {
            $adjustment = $this->lockPending($adjustment);
            $lines = $adjustment->items()->get();
            $costs = BranchInventory::query()->where('branch_id', $adjustment->branch_id)
                ->whereIn('inventory_item_id', $lines->pluck('inventory_item_id'))->pluck('average_unit_cost', 'inventory_item_id');

            $changes = [];
            foreach ($lines as $line) {
                $variance = Quantity::fromString($line->variance_quantity);
                $line->forceFill(['unit_cost' => $costs[$line->inventory_item_id] ?? null])->save();
                if ($variance->isZero()) {
                    continue;
                }
                $changes[] = new StockChange(
                    $adjustment->branch_id,
                    $line->inventory_item_id,
                    $variance->isPositive() ? InventoryMovementType::AdjustmentIn : InventoryMovementType::AdjustmentOut,
                    $variance->isPositive() ? $variance : $variance->negate(),
                    null, // the average cost is unchanged by a count
                    "{$adjustment->adjustment_number}: {$adjustment->reason}",
                    $adjustment,
                );
            }

            try {
                $this->ledger->post($actor, $changes);
            } catch (InsufficientStock $e) {
                throw ValidationException::withMessages(['adjustment' => __('Only :available of :item is on hand now, so :requested cannot be removed. Reject this adjustment and count again.', [
                    'available' => $e->available, 'item' => $e->itemName, 'requested' => $e->requested,
                ])]);
            }

            $adjustment->forceFill([
                'status' => StockAdjustmentStatus::Posted,
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'decision_notes' => $notes,
                'posted_at' => now(),
            ])->save();

            $this->audit->record('stock_adjustment.posted', $adjustment, ['status' => 'pending'], [
                'status' => 'posted',
                'approved_by_author' => $ownSubmission,
                'movements' => count($changes),
                'notes' => $notes,
            ], $adjustment->branch_id, $actor);

            return $adjustment;
        });
    }

    private function lockPending(StockAdjustment $adjustment): StockAdjustment
    {
        $adjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
        if ($adjustment->status !== StockAdjustmentStatus::Pending) {
            throw ValidationException::withMessages(['adjustment' => __('Adjustment :number was already :status.', ['number' => $adjustment->adjustment_number, 'status' => strtolower($adjustment->status->label())])]);
        }

        return $adjustment;
    }
}
