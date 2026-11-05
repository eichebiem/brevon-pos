<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Locations\TerminalService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** POS terminals per location (FR-106, D-060). Route middleware: permission:locations.manage. */
class TerminalController extends Controller
{
    public function __construct(private readonly TerminalService $terminals) {}

    public function store(Request $request, Branch $location): RedirectResponse
    {
        // The code is assigned by the app (D-122); only the name is entered.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $terminal = $this->terminals->create($actor, $location, (string) $validated['name']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal :code added.', ['code' => $terminal->terminal_code])]);

        return back();
    }

    public function update(Request $request, Terminal $terminal): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->terminals->update($actor, $terminal, (string) $validated['name'], (bool) $validated['is_active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal saved.')]);

        return back();
    }

    public function reassign(Request $request, Terminal $terminal): RedirectResponse
    {
        $validated = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);

        /** @var User $actor */
        $actor = $request->user();
        $destination = Branch::query()->findOrFail((int) $validated['branch_id']);
        $this->terminals->reassign($actor, $terminal, $destination);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal :code moved to :location.', ['code' => $terminal->terminal_code, 'location' => $destination->code])]);

        return back();
    }
}
