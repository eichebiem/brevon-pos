<?php

namespace App\Domain\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * The one order roles are shown in (D-180): Owner, Manager, Cashier first,
 * always, then every other role in the order it was created (id breaks ties).
 *
 * The built-in roles are placed by name, not by when they were created, so
 * the order holds on every database, including older ones where the roles
 * were made in a different order.
 */
final class RoleOrder
{
    /** @return Builder<Role> */
    public static function query(): Builder
    {
        // One placeholder per built-in role, in AccessGuard::SYSTEM_ROLES order.
        return Role::query()
            ->orderByRaw('CASE name WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', AccessGuard::SYSTEM_ROLES)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Sort roles already loaded (e.g. one user's roles) the same way.
     *
     * @template TRole of Model
     *
     * @param  Collection<int, TRole>  $roles
     * @return Collection<int, TRole>
     */
    public static function sort(Collection $roles): Collection
    {
        $rank = array_flip(AccessGuard::SYSTEM_ROLES);

        return $roles->sortBy([
            fn (Model $a, Model $b) => ($rank[$a->getAttribute('name')] ?? count($rank)) <=> ($rank[$b->getAttribute('name')] ?? count($rank)),
            fn (Model $a, Model $b) => [$a->getAttribute('created_at'), $a->getKey()] <=> [$b->getAttribute('created_at'), $b->getKey()],
        ])->values();
    }
}
