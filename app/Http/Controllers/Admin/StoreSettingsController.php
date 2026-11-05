<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\StoreSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Store settings (FR-013, FR-101). Route middleware: permission:settings.manage. */
class StoreSettingsController extends Controller
{
    public function __construct(private readonly StoreSettings $settings) {}

    public function edit(): Response
    {
        return Inertia::render('admin/settings/Store', ['settings' => $this->settings->forDisplay()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->settings->update(
            $actor,
            ['name' => (string) $validated['name'], 'receipt_footer' => $validated['receipt_footer'] ?? null],
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
