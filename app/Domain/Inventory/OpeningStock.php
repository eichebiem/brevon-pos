<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * First stock count for a location (D-051, D-093). Only items with no stock
 * history at that location qualify, so opening stock can never be entered
 * twice; later corrections are adjustments (Phase 7).
 */
class OpeningStock
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Active items without movements at the location. Pop-ups only stock
     * finished goods linked to a finished-stock product (D-063).
     *
     * @return Collection<int, InventoryItem>
     */
    public function eligibleItems(Branch $location): Collection
    {
        return $this->eligibleQuery($location)->with('unit')->orderBy('name')->get();
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}>  $lines
     */
    public function post(User $actor, Branch $location, array $lines): int
    {
        if (! $location->acceptsNewOperationsOn(BusinessClock::today())) {
            throw ValidationException::withMessages(['lines' => __('Opening stock can only be entered at a location that is active and within its operating dates.')]);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => trim((string) $line['quantity']) !== ''));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Enter a quantity for at least one item.')]);
        }

        return DB::transaction(function () use ($actor, $location, $lines) {
            // Serialise opening-stock entry per location so two people cannot both post it.
            Branch::query()->whereKey($location->id)->lockForUpdate()->first();

            $eligible = $this->eligibleQuery($location)->with('unit')->get()->keyBy('id');
            $changes = [];
            $errors = [];

            foreach ($lines as $index => $line) {
                $item = $eligible->get($line['inventory_item_id']);

                if ($item === null) {
                    $errors["lines.{$index}.quantity"] = __('This item already has stock history here or cannot be stocked at this location.');

                    continue;
                }

                $quantity = $this->quantity((string) $line['quantity'], $item->unit->precision ?? 3);

                if ($quantity === null) {
                    $errors["lines.{$index}.quantity"] = ($item->unit->precision ?? 3) === 0
                        ? __('Enter a whole number greater than zero.')
                        : __('Enter a quantity greater than zero.');

                    continue;
                }

                $cost = trim((string) $line['unit_cost']);

                if ($cost !== '' && preg_match('/^\d{1,10}(\.\d{1,4})?$/', $cost) !== 1) {
                    $errors["lines.{$index}.unit_cost"] = __('Enter a cost of zero or more, with up to 4 decimal places.');

                    continue;
                }

                $changes[] = new StockChange($location->id, $item->id, InventoryMovementType::Opening, $quantity, $cost === '' ? null : $cost, __('Opening stock'));
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->ledger->post($actor, $changes);

            $this->audit->record('inventory.opening_stock', $location, null, [
                'items' => collect($changes)->map(fn (StockChange $c) => [
                    'sku' => $eligible->get($c->inventoryItemId)?->sku,
                    'quantity' => $c->quantity->toString(),
                ])->all(),
            ], $location->id, $actor);

            return count($changes);
        });
    }

    /**
     * @return Builder<InventoryItem>
     */
    private function eligibleQuery(Branch $location): Builder
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from((new InventoryMovement)->getTable())
                ->whereColumn('inventory_movements.inventory_item_id', 'inventory_items.id')
                ->where('inventory_movements.branch_id', $location->id))
            ->when(! $location->isPermanent(), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p
                ->where('inventory_tracking_mode', InventoryTrackingMode::FinishedStock)));
    }

    private function quantity(string $raw, int $precision): ?Quantity
    {
        try {
            $quantity = Quantity::fromString($raw);
        } catch (InvalidArgumentException) {
            return null;
        }

        $decimals = str_contains(trim($raw), '.') ? strlen(rtrim(substr(trim($raw), strpos(trim($raw), '.') + 1), '0')) : 0;

        return $quantity->isPositive() && $decimals <= $precision ? $quantity : null;
    }
}
