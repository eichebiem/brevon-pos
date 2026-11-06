<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes off stock that can no longer be sold or used — dropped, spoiled,
 * expired, or a pop-up's unsold doughnuts (FR-056, FR-061; D-063, D-132).
 * Posted at once with a required reason; each line is a waste movement.
 */
class StockWaste
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly StockLocations $locations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null}>  $lines
     */
    public function record(User $actor, Branch $location, string $reason, array $lines): int
    {
        $lines = array_values(array_filter($lines, fn (array $l) => trim((string) $l['quantity']) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Enter a quantity for at least one item.')]);
        }

        return DB::transaction(function () use ($actor, $location, $reason, $lines) {
            $items = $this->locations->itemsQuery($location)->with('unit')
                ->whereIn('id', array_column($lines, 'inventory_item_id'))->get()->keyBy('id');

            $changes = [];
            $errors = [];
            foreach ($lines as $i => $line) {
                /** @var InventoryItem|null $item */
                $item = $items->get((int) $line['inventory_item_id']);
                if ($item === null) {
                    $errors["lines.{$i}.quantity"] = __('This item is not stocked at :location.', ['location' => $location->name]);

                    continue;
                }
                $precision = $item->unit->precision ?? 3;
                $quantity = QuantityInput::parse((string) $line['quantity'], $precision);
                if ($quantity === null) {
                    $errors["lines.{$i}.quantity"] = QuantityInput::message($precision);

                    continue;
                }
                $changes[] = new StockChange($location->id, $item->id, InventoryMovementType::Waste, $quantity, null, $reason);
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            try {
                $this->ledger->post($actor, $changes);
            } catch (InsufficientStock $e) {
                throw ValidationException::withMessages(['lines' => __('Only :available of :item is on hand, so :requested cannot be written off.', [
                    'available' => $e->available, 'item' => $e->itemName, 'requested' => $e->requested,
                ])]);
            }

            $this->audit->record('inventory.waste', $location, null, [
                'reason' => $reason,
                'items' => array_map(fn (StockChange $c) => [
                    'sku' => $items->get($c->inventoryItemId)?->sku,
                    'quantity' => $c->quantity->toString(),
                ], $changes),
            ], $location->id, $actor);

            return count($changes);
        });
    }
}
