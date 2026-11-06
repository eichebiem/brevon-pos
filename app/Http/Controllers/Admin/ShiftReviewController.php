<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Locations\LocationContext;
use App\Domain\Shared\Money;
use App\Domain\Shifts\CashDrawer;
use App\Domain\Shifts\ShiftSummary;
use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manager shift review (FR-080–FR-082, UC-008, D-128–D-130): shifts at the
 * selected location, one shift's summary, sign-off and corrections.
 * Route middleware: permission:shifts.review.
 */
class ShiftReviewController extends Controller
{
    public function __construct(
        private readonly LocationContext $locations,
        private readonly ShiftSummary $summary,
        private readonly CashDrawer $drawer,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $location = $this->locations->current($user);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,closed,needs_review'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $status = $filters['status'] ?? null;

        $shifts = CashierShift::query()
            ->where('branch_id', $location->id ?? 0)
            ->with(['cashier:id,name', 'terminal:id,terminal_code', 'reviewer:id,name'])
            ->when($status === 'open', fn ($q) => $q->where('status', ShiftStatus::Open))
            ->when(in_array($status, ['closed', 'needs_review'], true), fn ($q) => $q->where('status', ShiftStatus::Closed))
            ->when($status === 'needs_review', fn ($q) => $q->whereNull('reviewed_at'))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('business_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('business_date', '<=', $d))
            ->orderByDesc('opened_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CashierShift $s) => [
                ...$this->row($s),
                'needs_review' => $this->summary->needsReview($s),
            ]);

        // "Needs review" also drops reviewed-at-null shifts whose difference is within the limit.
        if ($status === 'needs_review') {
            $shifts->setCollection($shifts->getCollection()->filter(fn (array $row) => $row['needs_review'])->values());
        }

        return Inertia::render('admin/shifts/Index', [
            'site' => $location?->only(['id', 'code', 'name']),
            'shifts' => $shifts,
            'filters' => (object) $filters,
        ]);
    }

    public function show(Request $request, CashierShift $shift): Response
    {
        $user = $this->authorizeShift($request, $shift);
        $shift->load(['cashier:id,name', 'terminal:id,terminal_code', 'branch:id,code,name', 'closer:id,name', 'reviewer:id,name']);

        return Inertia::render('admin/shifts/Show', [
            'shift' => [
                ...$this->row($shift),
                'location' => $shift->branch?->name,
                'location_code' => $shift->branch?->code,
                'business_date' => $shift->business_date->toDateString(),
                'closed_by' => $shift->closer?->name,
                'closing_notes' => $shift->closing_notes,
                'review_notes' => $shift->review_notes,
                'cashier_id' => $shift->user_id,
            ],
            'summary' => $this->summary->build($shift),
            'movements' => $this->summary->movements($shift),
            'movementTypes' => array_map(fn (CashMovementType $t) => ['value' => $t->value, 'label' => $t->label()], CashMovementType::manual()),
            'can' => [
                'record' => $shift->isOpen() && $user->can('cash-movements.create'),
                'close' => $shift->isOpen(),
                'review' => ! $shift->isOpen() && $shift->reviewed_at === null && $shift->user_id !== $user->id,
                'correct' => ! $shift->isOpen() && $shift->user_id !== $user->id,
                'decide' => $shift->isOpen(),
            ],
            'me' => $user->id,
        ]);
    }

    public function review(Request $request, CashierShift $shift): RedirectResponse
    {
        $user = $this->authorizeShift($request, $shift);
        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:1000']]);

        $this->drawer->review($user, $shift, $data['review_notes'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shift :number reviewed.', ['number' => $shift->shift_number])]);

        return back();
    }

    public function correct(Request $request, CashierShift $shift): RedirectResponse
    {
        $user = $this->authorizeShift($request, $shift);
        $data = $request->validate([
            'corrects' => ['required', 'in:expected,counted'],
            'amount' => ['required', 'regex:/^-?\d{1,6}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['amount.regex' => __('Enter an amount like 50.00 or -50.00.')]);

        $this->drawer->correct($user, $shift, $data['corrects'], Money::fromDecimal($data['amount']), $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Correction added to shift :number.', ['number' => $shift->shift_number])]);

        return back();
    }

    private function authorizeShift(Request $request, CashierShift $shift): User
    {
        /** @var User $user */
        $user = $request->user();
        // Reviewers only see shifts at locations they work at.
        abort_unless($user->worksAt($shift->branch_id), 404);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CashierShift $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->shift_number,
            'status' => $s->status->value,
            'cashier' => $s->cashier?->name,
            'terminal' => $s->terminal?->terminal_code,
            'opened_at' => $s->opened_at->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'opening_cash' => $s->opening_cash,
            'expected_cash' => $s->expected_cash,
            'counted_cash' => $s->counted_cash,
            'variance' => $s->variance,
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'reviewed_by' => $s->reviewer?->name,
        ];
    }
}
