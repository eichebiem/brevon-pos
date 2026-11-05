<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Cashier screen. Checkout arrives in Phase 4b/4c; for now it shows the shift state. */
class PosController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('pos/Index', [
            'canOpenShift' => $user->can('shifts.open'),
        ]);
    }
}
