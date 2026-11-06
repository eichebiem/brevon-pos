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
            'cash_approval_over' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'shift_review_variance_over' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'discount_approval_over' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $values = ['name' => (string) $validated['name'], 'receipt_footer' => $validated['receipt_footer'] ?? null];
        if (isset($validated['cash_approval_over'])) {
            $values['cash_approval_over'] = (string) $validated['cash_approval_over'];
        }
        if (isset($validated['shift_review_variance_over'])) {
            $values['shift_review_variance_over'] = (string) $validated['shift_review_variance_over'];
        }
        if (isset($validated['discount_approval_over'])) {
            $values['discount_approval_over'] = (string) $validated['discount_approval_over'];
        }
        $this->settings->update(
            $actor,
            $values,
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
