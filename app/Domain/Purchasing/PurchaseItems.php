<?php

namespace App\Domain\Purchasing;

use App\Enums\InventoryItemType;
use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * What can be bought and where (D-135, D-063): ingredients, packaging and
 * retail items, received at the permanent branch only. Doughnuts are baked,
 * not bought, and pop-ups get them by dispatch.
 */
final class PurchaseItems
{
    /**
     * @return Builder<InventoryItem>
     */
    public static function query(): Builder
    {
        return InventoryItem::query()->where('is_active', true)
            ->where('item_type', '!=', InventoryItemType::FinishedGood);
    }

    /**
     * @return list<array{id: int, sku: string, name: string, unit: string|null, precision: int, track_lot: bool}>
     */
    public static function forForm(): array
    {
        return array_values(self::query()->with('unit')->orderBy('name')->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit?->code,
                'precision' => $item->unit->precision ?? 3,
                'track_lot' => $item->track_lot,
            ])->all());
    }

    public static function assertReceivingLocation(Branch $location): void
    {
        if (! $location->isPermanent()) {
            throw ValidationException::withMessages(['location' => __('Deliveries are received at the main branch. Pop-ups get doughnuts by dispatch.')]);
        }
    }

    /**
     * A unit cost typed by staff: 0 or more, up to 4 decimal places.
     *
     * @return numeric-string|null
     */
    public static function parseCost(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if (preg_match('/^\d{1,10}(\.\d{1,4})?$/', $raw) !== 1 || ! is_numeric($raw)) {
            return null;
        }

        return bcadd($raw, '0', 4);
    }

    /**
     * Quantity × unit cost, rounded half-up to the centavo.
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitCost
     * @return numeric-string
     */
    public static function lineTotal(string $quantity, string $unitCost): string
    {
        return bcadd(bcmul($quantity, $unitCost, 7), '0.005', 2);
    }
}
