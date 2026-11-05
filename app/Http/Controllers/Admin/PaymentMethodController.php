<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\PaymentMethodService;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Payment method settings (FR-036, D-097). Route middleware: permission:settings.manage. */
class PaymentMethodController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/payment-methods/Index', [
            'methods' => PaymentMethod::query()->orderBy('id')->get()->map(fn (PaymentMethod $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'type' => $m->type->value,
                'requires_reference' => $m->requires_reference,
                'is_active' => $m->is_active,
            ]),
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod, PaymentMethodService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $service->update($actor, $paymentMethod, [
            'name' => trim((string) $validated['name']),
            'is_active' => (bool) $validated['is_active'],
            'requires_reference' => (bool) $validated['requires_reference'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $validated['name']])]);

        return back();
    }
}
