<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Enums\InventoryItemType;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock item master data (SRS §8.7, FR-057; D-134). Ingredients, packaging
 * and retail items are added here; doughnut stock items come from their
 * product (D-089), so only their reorder level and perishable flag change
 * here. SKUs never change; the unit is fixed once the item has movements.
 */
class InventoryItemService
{
    public const EDITABLE_WHEN_LINKED = ['reorder_level', 'is_perishable'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{sku: string, name: string, item_type: string, unit_of_measure_id: int, reorder_level: string, is_perishable: bool}  $data
     */
    public function create(User $actor, array $data): InventoryItem
    {
        if (InventoryItemType::from($data['item_type']) === InventoryItemType::FinishedGood) {
            throw ValidationException::withMessages(['item_type' => __('Doughnut stock items are created from their product.')]);
        }
        $this->assertReorderLevel($data['reorder_level'], (int) $data['unit_of_measure_id']);

        return DB::transaction(function () use ($actor, $data) {
            $item = InventoryItem::query()->create([...$data, 'sku' => strtoupper(trim($data['sku'])), 'is_active' => true]);
            $this->audit->record('inventory_item.created', $item, null, $item->only(['sku', 'name', 'item_type', 'unit_of_measure_id', 'reorder_level']), null, $actor);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, InventoryItem $item, array $data): InventoryItem
    {
        $linked = $item->product()->exists();
        $allowed = $linked ? self::EDITABLE_WHEN_LINKED : ['name', 'unit_of_measure_id', 'reorder_level', 'is_perishable', 'is_active'];
        $data = array_intersect_key($data, array_flip($allowed));

        $unitId = (int) ($data['unit_of_measure_id'] ?? $item->unit_of_measure_id);
        if ($unitId !== $item->unit_of_measure_id && InventoryMovement::query()->where('inventory_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['unit_of_measure_id' => __('The unit cannot change once the item has stock history.')]);
        }
        if (isset($data['reorder_level'])) {
            $this->assertReorderLevel((string) $data['reorder_level'], $unitId);
        }

        return DB::transaction(function () use ($actor, $item, $data) {
            $old = $item->only(array_keys($data));
            $item->fill($data)->save();
            $changed = array_intersect_key($item->getChanges(), $data);
            if ($changed !== []) {
                $this->audit->record('inventory_item.updated', $item, array_intersect_key($old, $changed), $changed, null, $actor);
            }

            return $item;
        });
    }

    private function assertReorderLevel(string $level, int $unitId): void
    {
        $precision = UnitOfMeasure::query()->whereKey($unitId)->value('precision') ?? 3;
        if (QuantityInput::parse($level, (int) $precision, allowZero: true) === null) {
            throw ValidationException::withMessages(['reorder_level' => QuantityInput::message((int) $precision, allowZero: true)]);
        }
    }
}
