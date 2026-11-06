<?php

namespace App\Domain\Reports;

use App\Domain\Shared\BusinessClock;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which locations a person may report on, and the filters of a report
 * request (FR-095, FR-105, FR-108, D-157). Someone who manages locations
 * sees every location; everyone else sees the locations assigned to them.
 * Closed and deactivated pop-ups stay in the list with their history.
 */
class ReportScope
{
    public const MAX_DAYS = 400;

    /**
     * @return Collection<int, Branch>
     */
    public function locations(User $user): Collection
    {
        $query = $user->can('locations.manage') ? Branch::query() : $user->branches();

        return $query->orderBy('branches.location_type')->orderBy('branches.name')->get();
    }

    /**
     * @param  'today'|'month'  $defaultRange
     */
    public function filters(Request $request, User $user, string $defaultRange = 'month'): ReportFilters
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'location' => ['nullable', 'string', 'max:20'],
            'cashier' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'integer'],
            'group' => ['nullable', 'in:day,week,month'],
        ]);

        $today = BusinessClock::today();
        $from = isset($validated['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['from'], BusinessClock::timezone()) : null;
        $to = isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['to'], BusinessClock::timezone()) : null;
        $to = $to ?: $today;
        $from = $from ?: ($defaultRange === 'today' ? $to : $to->startOfMonth());
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => __('Choose at most :days days.', ['days' => self::MAX_DAYS])]);
        }

        $allowed = array_values($this->locations($user)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $location = $validated['location'] ?? 'all';
        if ($location === 'all') {
            $branchIds = $allowed;
        } else {
            if (! in_array((int) $location, $allowed, true)) {
                throw ValidationException::withMessages(['location' => __('Choose one of your locations.')]);
            }
            $branchIds = [(int) $location];
        }

        /** @var 'day'|'week'|'month' $group */
        $group = $validated['group'] ?? 'day';

        return new ReportFilters(
            $from, $to, $branchIds,
            isset($validated['cashier']) ? (int) $validated['cashier'] : null,
            isset($validated['category']) ? (int) $validated['category'] : null,
            isset($validated['payment_method']) ? (int) $validated['payment_method'] : null,
            $group,
            $location === 'all' ? 'all' : (string) (int) $location,
        );
    }
}
