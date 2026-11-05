<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Locations\LocationService;
use App\Domain\Shared\BusinessClock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Operating locations (FR-011, FR-103, FR-104, FR-108). Route middleware: permission:locations.manage. */
class LocationController extends Controller
{
    public function __construct(private readonly LocationService $locations) {}

    public function index(): Response
    {
        $today = BusinessClock::today();

        $rows = Branch::query()
            ->withCount(['terminals', 'users'])
            ->withCount(['products' => fn ($q) => $q->where('product_branch.is_available', true)])
            ->orderBy('location_type')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'type' => $b->location_type->value,
                'city' => $b->city_municipality,
                'status' => $b->operatingStatus($today),
                'operating_starts_on' => $b->operating_starts_on?->toDateString(),
                'operating_ends_on' => $b->operating_ends_on?->toDateString(),
                'terminals_count' => $b->terminals_count,
                'users_count' => $b->users_count,
                'products_count' => $b->products_count,
            ]);

        return Inertia::render('admin/locations/Index', ['locations' => $rows, 'today' => $today->toDateString()]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/locations/Form', ['site' => null, 'terminals' => [], 'destinations' => []]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $location = $this->locations->createPopUp($actor, $request->validated(), $request->boolean('copy_availability'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pop-up :code created. Add a terminal so cashiers can sell there.', ['code' => $location->code])]);

        return to_route('admin.locations.edit', $location);
    }

    public function edit(Branch $location): Response
    {
        $today = BusinessClock::today();

        // Not named "location": that key is the shared location-context prop.
        return Inertia::render('admin/locations/Form', [
            'site' => [
                ...$location->only(['id', 'code', 'name', 'address_line', 'barangay', 'city_municipality', 'province', 'postal_code', 'is_active', 'deactivation_reason']),
                'type' => $location->location_type->value,
                'status' => $location->operatingStatus($today),
                'operating_starts_on' => $location->operating_starts_on?->toDateString(),
                'operating_ends_on' => $location->operating_ends_on?->toDateString(),
                'deactivated_at' => $location->deactivated_at?->toIso8601String(),
            ],
            'terminals' => $location->terminals()->orderBy('terminal_code')->get()->map(fn (Terminal $t) => $t->only(['id', 'terminal_code', 'name', 'is_active'])),
            'destinations' => Branch::query()->whereKeyNot($location->id)->orderBy('location_type')->orderBy('name')->get()
                ->filter(fn (Branch $b) => $b->acceptsNewOperationsOn($today))
                ->map(fn (Branch $b) => ['id' => $b->id, 'label' => $b->label()])->values(),
        ]);
    }

    public function update(LocationRequest $request, Branch $location): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->locations->update($actor, $location, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Location saved.')]);

        return back();
    }

    public function deactivate(Request $request, Branch $location): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        /** @var User $actor */
        $actor = $request->user();
        $this->locations->deactivate($actor, $location, (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code deactivated. Its history is kept.', ['code' => $location->code])]);

        return back();
    }

    public function reactivate(Request $request, Branch $location): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->locations->reactivate($actor, $location);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code reactivated.', ['code' => $location->code])]);

        return back();
    }

    public function copyAvailability(Request $request, Branch $location): RedirectResponse
    {
        abort_if($location->isPermanent(), 404);

        $count = $this->locations->copyAvailabilityFromMain($location);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count product made available.|:count products made available.', $count, ['count' => $count])]);

        return back();
    }
}
