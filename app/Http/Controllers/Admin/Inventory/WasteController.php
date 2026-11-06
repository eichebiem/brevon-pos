<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Domain\Inventory\StockLocations;
use App\Domain\Inventory\StockWaste;
use App\Domain\Locations\LocationContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Writing off waste with a reason (FR-056, FR-061; D-132). Route middleware: permission:inventory.adjust. */
class WasteController extends Controller
{
    public function __construct(
        private readonly StockLocations $locations,
        private readonly StockWaste $waste,
        private readonly LocationContext $context,
    ) {}

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $choices = $this->locations->forUser($user);
        $wanted = (int) $request->integer('location');
        $location = $choices->firstWhere('id', $wanted) ?? $choices->first();

        return Inertia::render('admin/inventory/Waste', [
            // Not "location": that name is the shared working-location prop.
            'site' => $location?->only(['id', 'code', 'name']),
            'locations' => $choices->map(fn (Branch $b) => $b->only(['id', 'code', 'name']))->values(),
            'items' => $location ? $this->locations->formItems($location) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.inventory_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
        ]);
        abort_unless($this->locations->canUse($user, (int) $data['location_id']), 404);

        $count = $this->waste->record(
            $user,
            Branch::query()->findOrFail((int) $data['location_id']),
            $data['reason'],
            array_values(array_map(fn (array $l) => ['inventory_item_id' => (int) $l['inventory_item_id'], 'quantity' => $l['quantity'] ?? null], $data['lines'])),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Waste recorded for :count item.|Waste recorded for :count items.', $count, ['count' => $count])]);

        // Show the waste where it happened: switch the working location when it
        // can be one; an ended pop-up cannot, so stay on the waste form.
        $location = Branch::query()->findOrFail((int) $data['location_id']);

        return $this->context->switchTo($user, $location)
            ? to_route('admin.inventory.movements', ['type' => 'waste'])
            : to_route('admin.inventory.waste', ['location' => $location->id]);
    }
}
