<?php

namespace App\Http\Controllers;

use App\Domain\Locations\LocationContext;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LocationSwitchController extends Controller
{
    public function __invoke(Request $request, LocationContext $context): RedirectResponse
    {
        $validated = $request->validate(['branch_id' => ['required', 'integer']]);

        /** @var User $user */
        $user = $request->user();
        $branch = Branch::query()->whereKey((int) $validated['branch_id'])->first();

        // Same response for "does not exist" and "not assigned": no enumeration.
        abort_if($branch === null || ! $context->switchTo($user, $branch), 403);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Now working at :location.', ['location' => $branch->name])]);

        return back();
    }
}
