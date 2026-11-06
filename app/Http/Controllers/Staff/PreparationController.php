<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Locations\LocationContext;
use App\Domain\Orders\PreparationBoard;
use App\Domain\Shared\BusinessClock;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The preparation screen (FR-047, FR-048): active orders at the user's
 * current location. Route middleware: permission:orders.update-status.
 * The page refreshes itself every few seconds.
 */
class PreparationController extends Controller
{
    public function index(Request $request, LocationContext $locations, PreparationBoard $board): Response
    {
        /** @var User $user */
        $user = $request->user();
        $location = $locations->current($user);

        return Inertia::render('staff/Preparation', [
            'site' => $location === null ? null : ['code' => $location->code, 'name' => $location->name],
            'board' => $location === null ? null : $board->for($location),
            'now' => BusinessClock::now()->toIso8601String(),
            'canCancel' => $user->can('orders.cancel'),
        ]);
    }
}
