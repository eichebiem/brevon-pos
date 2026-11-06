<?php

namespace App\Domain\Production;

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\QuantityInput;
use App\Domain\Inventory\StockChange;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shared\Quantity;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Enums\ProductionBatchStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchItem;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production batches (FR-067–FR-071; D-142, D-143).
 *
 * A batch is saved as a draft (production.create) with what was planned,
 * what came out good, what was rejected, and the ingredients used —
 * worked out from the recipe for good + rejected pieces, and adjustable.
 * Posting (production.post) is one ledger call: production_consumption for
 * each ingredient and production_output for the good pieces, at the cost
 * of the ingredients spread over the good pieces. Rejects never enter stock
 * and are kept on the batch with their reason (FR-070). A batch posts once.
 * Production happens at the permanent branch only (D-063).
 */
class ProductionBatches
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly NumberSequence $numbers,
        private readonly RecipeBook $recipes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Doughnuts that can be produced: finished-stock products with a recipe.
     *
     * @return Collection<int, Product>
     */
    public function producible(): Collection
    {
        return Product::query()->where('is_active', true)
            ->where('inventory_tracking_mode', InventoryTrackingMode::FinishedStock)
            ->where('product_type', 'finished_good')
            ->whereNotNull('inventory_item_id')
            ->whereExists(fn ($q) => $q->from('recipes')->whereColumn('recipes.product_id', 'products.id')->where('recipes.is_active', true)->whereNull('recipes.product_variant_id'))
            ->with('inventoryItem.unit')
            ->orderBy('name')->get();
    }

    /**
     * @param  array{product_id: int, planned: string|null, actual: string|null, rejected: string|null, reject_reason: string|null, notes: string|null, usage: array<int, string|null>}  $data
     */
    public function create(User $actor, Branch $location, array $data): ProductionBatch
    {
        if (! $location->isPermanent()) {
            throw ValidationException::withMessages(['product_id' => __('Doughnuts are made at the main branch.')]);
        }
        [$product, $recipe] = $this->productAndRecipe($data['product_id']);

        return DB::transaction(function () use ($actor, $location, $data, $product, $recipe) {
            $values = $this->values($product, $recipe, $data);
            $today = BusinessClock::today();
            $number = sprintf('%s-%s-PB%02d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'production_batch', $today));

            $batch = new ProductionBatch;
            $batch->forceFill([
                'branch_id' => $location->id,
                'batch_number' => $number,
                'product_id' => $product->id,
                'recipe_id' => $recipe->id,
                'produced_at' => now(),
                'status' => ProductionBatchStatus::Draft,
                'created_by' => $actor->id,
                ...$values['header'],
            ])->save();
            $this->writeUsage($batch, $values['usage']);

            $this->audit->record('production_batch.created', $batch, null, ['number' => $number, 'product' => $product->sku, ...$values['header']], $location->id, $actor);

            return $batch;
        });
    }

    /**
     * @param  array{product_id: int, planned: string|null, actual: string|null, rejected: string|null, reject_reason: string|null, notes: string|null, usage: array<int, string|null>}  $data
     */
    public function update(User $actor, ProductionBatch $batch, array $data): ProductionBatch
    {
        [$product, $recipe] = $this->productAndRecipe($data['product_id']);

        return DB::transaction(function () use ($actor, $batch, $data, $product, $recipe) {
            $batch = $this->lockDraft($batch);
            $values = $this->values($product, $recipe, $data);
            $before = $batch->only(['product_id', 'planned_quantity', 'actual_quantity', 'rejected_quantity']);

            $batch->items()->get()->each->delete();
            $batch->forceFill(['product_id' => $product->id, 'recipe_id' => $recipe->id, ...$values['header']])->save();
            $this->writeUsage($batch, $values['usage']);

            $this->audit->record('production_batch.updated', $batch, $before, $batch->only(array_keys($before)), $batch->branch_id, $actor);

            return $batch;
        });
    }

    /** FR-068, FR-069: one atomic stock operation; a batch posts once (T-PRD-B3). */
    public function post(User $actor, ProductionBatch $batch): ProductionBatch
    {
        if (! $actor->can('production.post')) {
            throw ValidationException::withMessages(['batch' => __('A manager must post this batch.')]);
        }

        return DB::transaction(function () use ($actor, $batch) {
            $batch = $this->lockDraft($batch);
            $product = Product::query()->findOrFail($batch->product_id);
            $lines = $batch->items()->get();
            $costs = BranchInventory::query()->where('branch_id', $batch->branch_id)
                ->whereIn('inventory_item_id', $lines->pluck('inventory_item_id'))->pluck('average_unit_cost', 'inventory_item_id');

            $reason = "{$batch->batch_number}";
            $changes = [];
            $total = '0';
            $costKnown = true;
            foreach ($lines as $line) {
                $cost = $costs[$line->inventory_item_id] ?? null;
                $line->forceFill(['unit_cost_snapshot' => $cost])->save();
                $used = Quantity::fromString($line->quantity_consumed);
                if (! $used->isPositive()) {
                    continue;
                }
                if (! is_string($cost) || ! is_numeric($cost)) {
                    $costKnown = false;
                } else {
                    $total = bcadd($total, bcmul($used->toString(), $cost, 8), 8);
                }
                $changes[] = new StockChange($batch->branch_id, $line->inventory_item_id, InventoryMovementType::ProductionConsumption, $used, null, $reason, $batch);
            }

            $good = Quantity::fromString($batch->actual_quantity);
            $unitCost = null;
            if ($good->isPositive()) {
                // Rejects are paid for by the good pieces.
                $unitCost = $costKnown ? bcadd(bcdiv($total, $good->toString(), 8), '0.00005', 4) : null;
                $changes[] = new StockChange($batch->branch_id, (int) $product->inventory_item_id, InventoryMovementType::ProductionOutput, $good, $unitCost, $reason, $batch);
            }

            try {
                $this->ledger->post($actor, $changes);
            } catch (InsufficientStock $e) {
                throw ValidationException::withMessages(['batch' => __('Not enough :item in stock: :available on hand, :requested needed. Receive or count it first.', [
                    'item' => $e->itemName, 'available' => $this->amount($e->available, $e->inventoryItemId), 'requested' => $this->amount($e->requested, $e->inventoryItemId),
                ])]);
            }

            $batch->forceFill([
                'status' => ProductionBatchStatus::Posted,
                'unit_cost' => $unitCost,
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'produced_at' => now(),
            ])->save();

            $this->audit->record('production_batch.posted', $batch, ['status' => 'draft'], [
                'status' => 'posted',
                'made' => $batch->actual_quantity,
                'rejected' => $batch->rejected_quantity,
                'unit_cost' => $unitCost,
                'movements' => count($changes),
            ], $batch->branch_id, $actor);

            return $batch;
        });
    }

    public function cancel(User $actor, ProductionBatch $batch, string $reason): ProductionBatch
    {
        return DB::transaction(function () use ($actor, $batch, $reason) {
            $batch = $this->lockDraft($batch);
            $batch->forceFill([
                'status' => ProductionBatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ])->save();
            $this->audit->record('production_batch.cancelled', $batch, ['status' => 'draft'], ['status' => 'cancelled', 'reason' => $reason], $batch->branch_id, $actor);

            return $batch;
        });
    }

    /**
     * @return array{0: Product, 1: Recipe}
     */
    private function productAndRecipe(int $productId): array
    {
        $product = $this->producible()->firstWhere('id', $productId);
        $recipe = $product ? $this->recipes->active($product) : null;
        if ($product === null || $recipe === null) {
            throw ValidationException::withMessages(['product_id' => __('Choose a doughnut that has a recipe.')]);
        }

        return [$product, $recipe];
    }

    private function lockDraft(ProductionBatch $batch): ProductionBatch
    {
        $batch = ProductionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        if ($batch->status !== ProductionBatchStatus::Draft) {
            throw ValidationException::withMessages(['batch' => __('Batch :number was already :status.', ['number' => $batch->batch_number, 'status' => strtolower($batch->status->label())])]);
        }

        return $batch;
    }

    /**
     * @param  array{product_id: int, planned: string|null, actual: string|null, rejected: string|null, reject_reason: string|null, notes: string|null, usage: array<int, string|null>}  $data
     * @return array{header: array<string, string|null>, usage: array<int, Quantity>}
     */
    private function values(Product $product, Recipe $recipe, array $data): array
    {
        $precision = $product->inventoryItem->unit->precision ?? 0;
        $errors = [];
        $planned = QuantityInput::parse((string) $data['planned'], $precision);
        if ($planned === null) {
            $errors['planned'] = QuantityInput::message($precision);
        }
        $actual = QuantityInput::parse((string) ($data['actual'] ?? ''), $precision, allowZero: true);
        if ($actual === null) {
            $errors['actual'] = QuantityInput::message($precision, allowZero: true);
        }
        $rejectedRaw = trim((string) ($data['rejected'] ?? ''));
        $rejected = $rejectedRaw === '' ? Quantity::zero() : QuantityInput::parse($rejectedRaw, $precision, allowZero: true);
        if ($rejected === null) {
            $errors['rejected'] = QuantityInput::message($precision, allowZero: true);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        /** @var Quantity $planned */
        /** @var Quantity $actual */
        /** @var Quantity $rejected */
        $made = $actual->add($rejected);
        if (! $made->isPositive()) {
            throw ValidationException::withMessages(['actual' => __('Enter how many came out good or were rejected.')]);
        }
        $reason = trim((string) $data['reject_reason']);
        if ($rejected->isPositive() && mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reject_reason' => __('Say why pieces were rejected.')]);
        }

        // Ingredient use: the recipe for every piece made, unless changed.
        $usage = $this->recipes->ingredientsFor($recipe, $made);
        $recipe->loadMissing('items.item.unit');
        foreach ($recipe->items as $line) {
            $raw = $data['usage'][$line->inventory_item_id] ?? null;
            if ($raw === null || trim($raw) === '') {
                $usage[$line->inventory_item_id] ??= Quantity::zero();

                continue;
            }
            $itemPrecision = $line->item->unit->precision ?? 3;
            $used = QuantityInput::parse($raw, $itemPrecision, allowZero: true);
            if ($used === null) {
                $errors["usage.{$line->inventory_item_id}"] = QuantityInput::message($itemPrecision, allowZero: true);

                continue;
            }
            $usage[$line->inventory_item_id] = $used;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'header' => [
                'planned_quantity' => $planned->toString(),
                'actual_quantity' => $actual->toString(),
                'rejected_quantity' => $rejected->toString(),
                'reject_reason' => $rejected->isPositive() ? $reason : null,
                'notes' => $data['notes'],
            ],
            'usage' => $usage,
        ];
    }

    /**
     * @param  array<int, Quantity>  $usage
     */
    private function writeUsage(ProductionBatch $batch, array $usage): void
    {
        ksort($usage);
        foreach ($usage as $itemId => $quantity) {
            (new ProductionBatchItem)->forceFill([
                'production_batch_id' => $batch->id,
                'inventory_item_id' => $itemId,
                'quantity_consumed' => $quantity->toString(),
            ])->save();
        }
    }

    /** "8750.000" → "8,750 g" for messages. */
    private function amount(string $quantity, int $itemId): string
    {
        $unit = InventoryItem::query()->with('unit')->find($itemId)?->unit;
        $decimals = str_contains($quantity, '.') ? strlen(rtrim(substr($quantity, strpos($quantity, '.') + 1), '0')) : 0;

        return trim(number_format((float) $quantity, $decimals).' '.($unit->code ?? ''));
    }
}
