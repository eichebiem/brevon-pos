<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Inventory\StockAdjustments;
use App\Domain\Inventory\StockLocations;
use App\Enums\StockAdjustmentKind;
use App\Enums\StockAdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Stock counts and adjustments with approval (FR-056, FR-061, BR-012; D-131). */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockLocations $locations,
        private readonly StockAdjustments $adjustments,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(StockAdjustmentStatus::class)]]);
        $locationIds = $user->branches()->pluck('branches.id');

        return Inertia::render('admin/inventory/adjustments/Index', [
            'adjustments' => StockAdjustment::query()
                ->whereIn('branch_id', $locationIds)
                ->with(['branch:id,code,name', 'creator:id,name', 'approver:id,name'])
                ->withCount('items')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderByRaw("status = 'pending' DESC")->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (StockAdjustment $a) => $this->row($a)),
            'filters' => (object) $filters,
            'pendingCount' => StockAdjustment::query()->whereIn('branch_id', $locationIds)->where('status', StockAdjustmentStatus::Pending)->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate([
            'kind' => ['nullable', Rule::enum(StockAdjustmentKind::class)],
            'location' => ['nullable', 'integer'],
        ]);
        $choices = $this->locations->forUser($user);
        $location = $choices->firstWhere('id', (int) ($data['location'] ?? 0)) ?? $choices->first();

        return Inertia::render('admin/inventory/adjustments/Create', [
            'kind' => $data['kind'] ?? StockAdjustmentKind::Count->value,
            // Not "location": that name is the shared working-location prop.
            'site' => $location?->only(['id', 'code', 'name']),
            'locations' => $choices->map(fn (Branch $b) => $b->only(['id', 'code', 'name']))->values(),
            'items' => $location ? $this->locations->formItems($location) : [],
            'postsImmediately' => $user->can('inventory.approve-adjustment'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'kind' => ['required', Rule::enum(StockAdjustmentKind::class)],
            'location_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
        ]);
        abort_unless($this->locations->canUse($user, (int) $data['location_id']), 404);

        $adjustment = $this->adjustments->create(
            $user,
            Branch::query()->findOrFail((int) $data['location_id']),
            StockAdjustmentKind::from($data['kind']),
            $data['reason'],
            array_values(array_map(fn (array $l) => ['inventory_item_id' => (int) $l['inventory_item_id'], 'quantity' => $l['quantity'] ?? null], $data['lines'])),
        );

        Inertia::flash('toast', $adjustment->status === StockAdjustmentStatus::Posted
            ? ['type' => 'success', 'message' => __('Adjustment :number posted.', ['number' => $adjustment->adjustment_number])]
            : ['type' => 'info', 'message' => __('Adjustment :number is waiting for approval.', ['number' => $adjustment->adjustment_number])]);

        return to_route('admin.inventory.adjustments.show', $adjustment);
    }

    public function show(Request $request, StockAdjustment $adjustment): Response
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($adjustment->branch_id), 404);
        $adjustment->load(['branch:id,code,name', 'creator:id,name', 'approver:id,name', 'items.item.unit']);

        return Inertia::render('admin/inventory/adjustments/Show', [
            'adjustment' => [
                ...$this->row($adjustment),
                'decision_notes' => $adjustment->decision_notes,
                'lines' => $adjustment->items->map(fn (StockAdjustmentItem $line) => [
                    'id' => $line->id,
                    'sku' => $line->item?->sku,
                    'name' => $line->item?->name,
                    'unit' => $line->item?->unit?->code,
                    'system_quantity' => $line->system_quantity,
                    'counted_quantity' => $line->counted_quantity,
                    'variance_quantity' => $line->variance_quantity,
                    'unit_cost' => $line->unit_cost,
                ])->values(),
            ],
            'canDecide' => $adjustment->status === StockAdjustmentStatus::Pending
                && $user->can('inventory.approve-adjustment')
                && $adjustment->created_by !== $user->id,
        ]);
    }

    public function approve(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($adjustment->branch_id), 404);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $this->adjustments->approve($user, $adjustment, $data['notes'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Adjustment :number posted.', ['number' => $adjustment->adjustment_number])]);

        return back();
    }

    public function reject(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->worksAt($adjustment->branch_id), 404);
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:500']]);

        $this->adjustments->reject($user, $adjustment, $data['notes']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Adjustment :number rejected.', ['number' => $adjustment->adjustment_number])]);

        return back();
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
    private function row(StockAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->adjustment_number,
            'kind' => $a->kind->value,
            'kind_label' => $a->kind->label(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'reason' => $a->reason,
            'location' => $a->branch?->only(['code', 'name']),
            'created_by' => $a->creator?->name,
            'created_at' => $a->created_at->toIso8601String(),
            'decided_by' => $a->approver?->name,
            'decided_at' => $a->decided_at?->toIso8601String(),
            'items_count' => $a->items_count ?? $a->items->count(),
        ];
    }
}
