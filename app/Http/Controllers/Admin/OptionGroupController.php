<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\OptionGroupService;
use App\Enums\OptionSelectionType;
use App\Http\Controllers\Controller;
use App\Models\OptionGroup;
use App\Models\ProductOption;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Option groups / add-ons (FR-018). */
class OptionGroupController extends Controller
{
    public function __construct(private readonly OptionGroupService $groups) {}

    public function index(): Response
    {
        $rows = OptionGroup::query()->withCount(['options', 'products'])->orderBy('name')->get()
            ->map(fn (OptionGroup $g) => [
                ...$g->only(['id', 'name', 'min_selections', 'max_selections', 'is_active']),
                'selection_type' => $g->selection_type->value,
                'options_count' => $g->options_count,
                'products_count' => $g->products_count,
            ]);

        return Inertia::render('admin/option-groups/Index', ['groups' => $rows]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/option-groups/Form', ['group' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->groups->save($actor, null, $this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Option group created.')]);

        return to_route('admin.option-groups.index');
    }

    public function edit(OptionGroup $optionGroup): Response
    {
        return Inertia::render('admin/option-groups/Form', [
            'group' => [
                ...$optionGroup->only(['id', 'name', 'min_selections', 'max_selections', 'is_active']),
                'selection_type' => $optionGroup->selection_type->value,
                'options' => $optionGroup->options()->get()->map(fn (ProductOption $o) => $o->only(['id', 'name', 'price_delta', 'is_active'])),
            ],
        ]);
    }

    public function update(Request $request, OptionGroup $optionGroup): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->groups->save($actor, $optionGroup, $this->validated($request, $optionGroup));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Option group saved.')]);

        return to_route('admin.option-groups.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?OptionGroup $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('option_groups', 'name')->ignore($group?->id)],
            'selection_type' => ['required', Rule::enum(OptionSelectionType::class)],
            'min_selections' => ['required', 'integer', 'min:0', 'max:20'],
            'max_selections' => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_active' => ['required', 'boolean'],
            'options' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'options.*.price_delta' => ['nullable', 'string', 'max:12'],
            'options.*.is_active' => ['required', 'boolean'],
        ]);
    }
}
