<?php

namespace App\Domain\Locations;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operating locations (FR-011, FR-103, FR-104, FR-108; D-057–D-065).
 * Exactly one permanent branch exists (seeded); this service only creates
 * pop-ups. Locations are deactivated, never deleted.
 */
class LocationService
{
    /** Columns an administrator may edit (code and type are immutable, D-057/D-065). */
    private const DETAIL_FIELDS = ['name', 'address_line', 'barangay', 'city_municipality', 'province', 'postal_code', 'phone', 'email'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPopUp(User $actor, array $data, bool $copyAvailabilityFromMain = false): Branch
    {
        return DB::transaction(function () use ($actor, $data, $copyAvailabilityFromMain) {
            $location = new Branch;
            $location->fill([
                ...array_intersect_key($data, array_flip(self::DETAIL_FIELDS)),
                'code' => strtoupper((string) $data['code']),
                'location_type' => LocationType::PopUp,
                'operating_starts_on' => $data['operating_starts_on'] ?? null,
                'operating_ends_on' => $data['operating_ends_on'] ?? null,
                'timezone' => (string) config('brevon.business_timezone'),
                'currency' => (string) config('brevon.currency'),
                'is_active' => true,
            ])->save();

            $copied = $copyAvailabilityFromMain ? $this->copyAvailabilityFromMain($location) : 0;

            $this->audit->record('location.created', $location, null, [
                ...$this->snapshot($location),
                'availability_copied_from_main' => $copied,
            ], $location->id, $actor);

            return $location;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Branch $location, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $location, $data) {
            $before = $this->snapshot($location);

            $location->fill(array_intersect_key($data, array_flip(self::DETAIL_FIELDS)));

            if (! $location->isPermanent()) {
                // Phase 4 adds: dates may not exclude days that already have sales (D-062).
                $location->fill([
                    'operating_starts_on' => $data['operating_starts_on'] ?? null,
                    'operating_ends_on' => $data['operating_ends_on'] ?? null,
                ]);
            }

            $location->save();

            [$old, $new] = AuditDiff::between($before, $this->snapshot($location->refresh()));
            if ($new !== []) {
                $this->audit->record('location.updated', $location, $old, $new, $location->id, $actor);
            }

            return $location;
        });
    }

    public function deactivate(User $actor, Branch $location, string $reason): Branch
    {
        if ($location->isPermanent()) {
            throw ValidationException::withMessages(['reason' => __('The permanent branch cannot be deactivated.')]);
        }

        if (! $location->is_active) {
            throw ValidationException::withMessages(['reason' => __('This location is already inactive.')]);
        }

        // D-062: shifts must be closed first, so no sale is left without a drawer count.
        if (CashierShift::query()->open()->where('branch_id', $location->id)->exists()) {
            throw ValidationException::withMessages(['reason' => __('Close every open shift at this location before deactivating it.')]);
        }

        return DB::transaction(function () use ($actor, $location, $reason) {
            $location->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->id,
                'deactivation_reason' => $reason,
            ])->save();

            $this->audit->record('location.deactivated', $location, ['is_active' => true], ['is_active' => false, 'reason' => $reason], $location->id, $actor);

            return $location;
        });
    }

    public function reactivate(User $actor, Branch $location): Branch
    {
        if ($location->is_active) {
            throw ValidationException::withMessages(['location' => __('This location is already active.')]);
        }

        return DB::transaction(function () use ($actor, $location) {
            $previousReason = $location->deactivation_reason;

            $location->forceFill([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ])->save();

            $this->audit->record('location.reactivated', $location, ['is_active' => false, 'reason' => $previousReason], ['is_active' => true], $location->id, $actor);

            return $location;
        });
    }

    /**
     * D-064: a new pop-up starts with nothing for sale; this explicit action
     * copies the main branch's available products that pop-ups may sell
     * (finished stock only, D-063). Returns the number copied.
     */
    public function copyAvailabilityFromMain(Branch $popUp): int
    {
        $main = Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();

        $ids = Product::query()
            ->where('inventory_tracking_mode', 'finished_stock')
            ->whereHas('locations', fn ($q) => $q->where('branches.id', $main->id)->where('product_branch.is_available', true))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('product_branch')->updateOrInsert(
                ['product_id' => $id, 'branch_id' => $popUp->id],
                ['is_available' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        return $ids->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Branch $location): array
    {
        return [
            ...$location->only(self::DETAIL_FIELDS),
            'code' => $location->code,
            'type' => $location->location_type->value,
            'operating_starts_on' => $location->operating_starts_on?->toDateString(),
            'operating_ends_on' => $location->operating_ends_on?->toDateString(),
        ];
    }
}
