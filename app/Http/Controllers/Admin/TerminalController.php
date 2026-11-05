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
        $request->merge(['terminal_code' => strtoupper(trim((string) $request->input('terminal_code')))]);
        $validated = $request->validate([
            'terminal_code' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/'],
            'name' => ['required', 'string', 'max:100'],
        ], ['terminal_code.regex' => __('Use capital letters, numbers and hyphens, e.g. POS-2.')]);

        /** @var User $actor */
        $actor = $request->user();
        $this->terminals->create($actor, $location, (string) $validated['terminal_code'], (string) $validated['name']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal added.')]);

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
