<?php

namespace App\Http\Controllers\Admin\Production;

use App\Domain\Inventory\StockDispatch;
use App\Domain\Production\ProductionBatches;
use App\Domain\Production\RecipeBook;
use App\Domain\Shared\Quantity;
use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchItem;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Production batches (FR-067–FR-070; D-142, D-143). At the main branch. */
class ProductionBatchController extends Controller
{
    public function __construct(
        private readonly ProductionBatches $batches,
        private readonly RecipeBook $recipes,
        private readonly StockDispatch $dispatch,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ProductionBatchStatus::class)]]);

        return Inertia::render('admin/production/batches/Index', [
            'batches' => ProductionBatch::query()
                ->whereIn('branch_id', $this->user($request)->branches()->pluck('branches.id'))
                ->with(['product:id,sku,name', 'creator:id,name'])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderByRaw("status = 'draft' DESC")->orderByDesc('id')
                ->paginate(25)->withQueryString()
                ->through(fn (ProductionBatch $b) => $this->row($b)),
            'filters' => (object) $filters,
            'draftCount' => ProductionBatch::query()->whereIn('branch_id', $this->user($request)->branches()->pluck('branches.id'))->where('status', ProductionBatchStatus::Draft)->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $main = $this->main($request);

        return $this->form($request, $main, null);
    }

    public function store(Request $request): RedirectResponse
    {
        $main = $this->main($request);
        $user = $this->user($request);
        $data = $this->validated($request);
        $post = $request->boolean('post');
        // Save and post is all or nothing: a failed post leaves no draft behind.
        $batch = DB::transaction(function () use ($user, $main, $data, $post) {
            $batch = $this->batches->create($user, $main, $data);

            return $post ? $this->batches->post($user, $batch) : $batch;
        });
        if ($post) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number posted to stock.', ['number' => $batch->batch_number])]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number saved as a draft.', ['number' => $batch->batch_number])]);
        }

        return to_route('admin.production.show', $batch);
    }

    public function show(Request $request, ProductionBatch $batch): Response
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($batch->branch_id), 404);
        $batch->load(['product.inventoryItem.unit', 'recipe', 'creator:id,name', 'poster:id,name', 'canceller:id,name', 'items.item.unit', 'branch:id,code,name']);
        $draft = $batch->status === ProductionBatchStatus::Draft;
        $onHand = BranchInventory::query()->where('branch_id', $batch->branch_id)->whereIn('inventory_item_id', $batch->items->pluck('inventory_item_id'))->pluck('quantity_on_hand', 'inventory_item_id');

        return Inertia::render('admin/production/batches/Show', [
            'batch' => [
                ...$this->row($batch),
                'location' => $batch->branch?->only(['code', 'name']),
                'unit' => $batch->product?->inventoryItem?->unit?->code,
                'recipe_version' => $batch->recipe?->version,
                'reject_reason' => $batch->reject_reason,
                'notes' => $batch->notes,
                'unit_cost' => $batch->unit_cost,
                'posted_by' => $batch->poster?->name,
                'posted_at' => $batch->posted_at?->toIso8601String(),
                'cancelled_by' => $batch->canceller?->name,
                'cancelled_at' => $batch->cancelled_at?->toIso8601String(),
                'cancel_reason' => $batch->cancel_reason,
                'lines' => $batch->items->map(fn (ProductionBatchItem $l) => [
                    'id' => $l->id,
                    'sku' => $l->item?->sku,
                    'name' => $l->item?->name,
                    'unit' => $l->item?->unit?->code,
                    'quantity' => $l->quantity_consumed,
                    'on_hand' => $draft ? (string) ($onHand[$l->inventory_item_id] ?? '0.000') : null,
                    'unit_cost' => $l->unit_cost_snapshot,
                ])->values(),
            ],
            'can' => [
                'edit' => $draft && $user->can('production.create'),
                'post' => $draft && $user->can('production.post'),
                'cancel' => $draft && $user->can('production.create'),
            ],
        ]);
    }

    public function edit(Request $request, ProductionBatch $batch): Response|RedirectResponse
    {
        abort_unless($this->user($request)->worksAt($batch->branch_id), 404);
        if ($batch->status !== ProductionBatchStatus::Draft) {
            return to_route('admin.production.show', $batch);
        }

        return $this->form($request, Branch::query()->findOrFail($batch->branch_id), $batch->load('items'));
    }

    public function update(Request $request, ProductionBatch $batch): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($batch->branch_id), 404);
        $data = $this->validated($request);
        $post = $request->boolean('post');
        DB::transaction(function () use ($user, $batch, $data, $post) {
            $this->batches->update($user, $batch, $data);
            if ($post) {
                $this->batches->post($user, $batch);
            }
        });
        if ($post) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number posted to stock.', ['number' => $batch->batch_number])]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number saved.', ['number' => $batch->batch_number])]);
        }

        return to_route('admin.production.show', $batch);
    }

    public function post(Request $request, ProductionBatch $batch): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($batch->branch_id), 404);
        $this->batches->post($user, $batch);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number posted to stock.', ['number' => $batch->batch_number])]);

        return back();
    }

    public function cancel(Request $request, ProductionBatch $batch): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($batch->branch_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->batches->cancel($user, $batch, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Batch :number cancelled.', ['number' => $batch->batch_number])]);

        return back();
    }

    private function form(Request $request, Branch $main, ?ProductionBatch $batch): Response
    {
        $onHand = BranchInventory::query()->where('branch_id', $main->id)->pluck('quantity_on_hand', 'inventory_item_id');

        return Inertia::render('admin/production/batches/Form', [
            'batch' => $batch ? [
                'id' => $batch->id,
                'number' => $batch->batch_number,
                'product_id' => $batch->product_id,
                'planned' => self::plain($batch->planned_quantity),
                'actual' => self::plain($batch->actual_quantity),
                'rejected' => Quantity::fromString($batch->rejected_quantity)->isZero() ? '' : self::plain($batch->rejected_quantity),
                'reject_reason' => $batch->reject_reason,
                'notes' => $batch->notes,
                'usage' => $batch->items->mapWithKeys(fn (ProductionBatchItem $l) => [$l->inventory_item_id => self::plain($l->quantity_consumed)]),
            ] : null,
            // Each doughnut with its recipe, so the form can work out ingredient use as you type.
            'products' => $this->batches->producible()->map(function (Product $p) use ($onHand) {
                $recipe = $this->recipes->active($p);
                $recipe?->load('items.item.unit');

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'unit' => $p->inventoryItem?->unit?->code,
                    'recipe' => $recipe ? [
                        'version' => $recipe->version,
                        'makes' => $recipe->output_quantity,
                        'lines' => $recipe->items->map(fn (RecipeItem $l) => [
                            'inventory_item_id' => $l->inventory_item_id,
                            'sku' => $l->item?->sku,
                            'name' => $l->item?->name,
                            'unit' => $l->item?->unit?->code,
                            'precision' => $l->item->unit->precision ?? 3,
                            'quantity' => $l->quantity_required,
                            'wastage_percent' => $l->wastage_percent,
                            'on_hand' => (string) ($onHand[$l->inventory_item_id] ?? '0.000'),
                        ])->values(),
                    ] : null,
                ];
            })->values(),
            'canPost' => $this->user($request)->can('production.post'),
        ]);
    }

    /**
     * @return array{product_id: int, planned: string|null, actual: string|null, rejected: string|null, reject_reason: string|null, notes: string|null, usage: array<int, string|null>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'planned' => ['nullable', 'string', 'max:20'],
            'actual' => ['nullable', 'string', 'max:20'],
            'rejected' => ['nullable', 'string', 'max:20'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'usage' => ['nullable', 'array', 'max:100'],
            'usage.*' => ['nullable', 'string', 'max:20'],
        ], ['product_id.required' => __('Choose a doughnut.')]);

        $usage = [];
        foreach ($data['usage'] ?? [] as $itemId => $raw) {
            $usage[(int) $itemId] = $raw;
        }

        return [
            'product_id' => (int) $data['product_id'],
            'planned' => $data['planned'] ?? null,
            'actual' => $data['actual'] ?? null,
            'rejected' => $data['rejected'] ?? null,
            'reject_reason' => $data['reject_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'usage' => $usage,
        ];
    }

    private function main(Request $request): Branch
    {
        $main = $this->dispatch->main();
        abort_unless($this->user($request)->worksAt($main->id), 404);

        return $main;
    }

    private static function plain(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProductionBatch $b): array
    {
        return [
            'id' => $b->id,
            'number' => $b->batch_number,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'product' => $b->product?->only(['id', 'sku', 'name']),
            'planned' => $b->planned_quantity,
            'actual' => $b->actual_quantity,
            'rejected' => $b->rejected_quantity,
            'created_by' => $b->creator?->name,
            'created_at' => $b->created_at->toIso8601String(),
            'produced_at' => $b->produced_at->toIso8601String(),
        ];
    }
}
