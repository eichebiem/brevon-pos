<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxRules;
use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Tax rules (FR-086; D-149). Route middleware: permission:settings.manage. */
class TaxRuleController extends Controller
{
    public function __construct(private readonly TaxRules $rules) {}

    public function index(TaxCalculator $taxes): Response
    {
        $inEffect = $taxes->rulesInEffect()->pluck('id')->all();

        return Inertia::render('admin/tax-rules/Index', [
            'rules' => TaxRule::query()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (TaxRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'code' => $r->code,
                'rate' => $r->rate,
                'inclusive' => $r->inclusive,
                'effective_from' => $r->effective_from->toDateString(),
                'effective_until' => $r->effective_until?->toDateString(),
                'is_active' => $r->is_active,
                'in_effect' => in_array($r->id, $inEffect, true),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/tax-rules/Form', ['rule' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = $this->rules->save($this->user($request), null, $this->input($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $rule->name])]);

        return to_route('admin.tax-rules.index');
    }

    public function edit(TaxRule $taxRule): Response
    {
        return Inertia::render('admin/tax-rules/Form', ['rule' => [
            'id' => $taxRule->id,
            'name' => $taxRule->name,
            'code' => $taxRule->code,
            'rate' => rtrim(rtrim($taxRule->rate, '0'), '.'),
            'inclusive' => $taxRule->inclusive,
            'effective_from' => $taxRule->effective_from->toDateString(),
            'effective_until' => $taxRule->effective_until?->toDateString(),
            'is_active' => $taxRule->is_active,
        ]]);
    }

    public function update(Request $request, TaxRule $taxRule): RedirectResponse
    {
        $this->rules->save($this->user($request), $taxRule, $this->input($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $taxRule->name])]);

        return to_route('admin.tax-rules.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
            'rate' => ['nullable', 'string', 'max:20'],
            'inclusive' => ['boolean'],
            'effective_from' => ['nullable', 'string', 'max:20'],
            'effective_until' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
