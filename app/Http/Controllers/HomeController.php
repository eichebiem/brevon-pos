<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends each user to the first area their permissions allow (D-074).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return match (true) {
            $user->can('dashboard.view') => to_route('dashboard'),
            $user->can('pos.access') => to_route('pos.index'),
            // A custom role for bakers (production only) starts on production.
            $user->can('production.create') => to_route('admin.production.index'),
            default => to_route('profile.edit'),
        };
    }
}
