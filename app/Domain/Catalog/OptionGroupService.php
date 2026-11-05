<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Enums\OptionSelectionType;
use App\Models\OptionGroup;
use App\Models\ProductOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Option groups and their options (FR-018). Options are deactivated, never
 * deleted, so past order lines keep their link.
 *
 * $data: name, selection_type, min_selections, max_selections, is_active,
 * options (list of {id?, name, price_delta, is_active}).
 */
class OptionGroupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?OptionGroup $group, array $data): OptionGroup
    {
        $data = $this->normalise($data);

        return DB::transaction(function () use ($actor, $group, $data) {
            $creating = $group === null;
            $group ??= new OptionGroup;
            $before = $creating ? [] : $this->snapshot($group);

            $group->fill(array_intersect_key($data, array_flip(['name', 'selection_type', 'min_selections', 'max_selections', 'is_active'])))->save();

            $existing = $group->options()->get()->keyBy('id');
            foreach ($data['options'] as $position => $row) {
                $attributes = ['name' => $row['name'], 'price_delta' => $row['price_delta'], 'is_active' => $row['is_active'], 'sort_order' => $position];
                if ($row['id'] !== null) {
                    /** @var ProductOption|null $option */
                    $option = $existing->get($row['id']);
                    if ($option === null) {
                        throw ValidationException::withMessages(['options' => __('An option does not belong to this group.')]);
                    }
                    $option->fill($attributes)->save();
                } else {
                    $group->options()->create($attributes);
                }
            }

            $active = $group->options()->where('is_active', true)->count();
            if ($group->min_selections > $active) {
                throw ValidationException::withMessages(['min_selections' => __('Customers must pick :min, but only :active options are active.', ['min' => $group->min_selections, 'active' => $active])]);
            }

            [$old, $new] = AuditDiff::between($before, $this->snapshot($group->refresh()));
            if ($new !== []) {
                $this->audit->record($creating ? 'option_group.created' : 'option_group.updated', $group, $creating ? null : $old, $new, actor: $actor);
            }

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $single = $data['selection_type'] === OptionSelectionType::Single->value;
        $min = (int) ($data['min_selections'] ?? 0);
        $max = $single ? 1 : (isset($data['max_selections']) && $data['max_selections'] !== '' ? (int) $data['max_selections'] : null);

        if ($single && $min > 1) {
            throw ValidationException::withMessages(['min_selections' => __('A single-choice group can require at most one selection.')]);
        }
        if ($max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_selections' => __('The maximum must be at least the minimum.')]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['options'] ?? [];
        $options = [];
        foreach ($rows as $i => $row) {
            try {
                $delta = Money::fromDecimal(trim((string) ($row['price_delta'] ?? '0')) ?: '0');
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["options.{$i}.price_delta" => __('Enter an amount like 10 or 10.50.')]);
            }
            if ($delta->isNegative()) {
                throw ValidationException::withMessages(["options.{$i}.price_delta" => __('Add-on prices cannot be negative.')]);
            }
            $options[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => trim((string) $row['name']),
                'price_delta' => $delta->toDecimal(),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];
        }

        return [...$data, 'min_selections' => $min, 'max_selections' => $max, 'is_active' => (bool) ($data['is_active'] ?? true), 'options' => $options];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OptionGroup $group): array
    {
        return [
            'name' => $group->name,
            'selection_type' => $group->selection_type->value,
            'min_selections' => $group->min_selections,
            'max_selections' => $group->max_selections,
            'is_active' => $group->is_active,
            'options' => $group->options()->get()->map(fn (ProductOption $o) => "{$o->name} +{$o->price_delta}".($o->is_active ? '' : ' (inactive)'))->all(),
        ];
    }
}
