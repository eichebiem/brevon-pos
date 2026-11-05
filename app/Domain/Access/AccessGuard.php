<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Privilege-escalation rules for user and role administration
 * (FR-010, D-021). Every user/role change goes through these checks.
 */
class AccessGuard
{
    /** Seeded roles cannot be renamed or deleted. */
    public const SYSTEM_ROLES = [
        PermissionCatalog::OWNER,
        PermissionCatalog::ADMIN,
        PermissionCatalog::MANAGER,
        PermissionCatalog::CASHIER,
        PermissionCatalog::PREPARATION_STAFF,
    ];

    /**
     * The actor may only manage accounts whose permissions they fully hold
     * (e.g. an Admin cannot reset an Owner's password).
     *
     * @throws AuthorizationException
     */
    public function assertCanManageUser(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            return;
        }

        if ($target->isOwner() && ! $actor->isOwner()) {
            throw new AuthorizationException(__('Only an Owner can manage an Owner account.'));
        }

        if ($this->permissionsOf($target)->diff($this->permissionsOf($actor))->isNotEmpty()) {
            throw new AuthorizationException(__('You cannot manage an account that has permissions you do not hold.'));
        }
    }

    /**
     * @param  list<string>  $roleNames
     *
     * @throws ValidationException
     */
    public function assertCanAssignRoles(User $actor, array $roleNames): void
    {
        if (in_array(PermissionCatalog::OWNER, $roleNames, true) && ! $actor->isOwner()) {
            throw ValidationException::withMessages(['roles' => __('Only an Owner can assign the Owner role.')]);
        }

        $held = $this->permissionsOf($actor);

        $requested = Role::query()->whereIn('name', $roleNames)->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();

        $missing = $requested->diff($held);

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot assign roles that include permissions you do not hold: :permissions.', [
                    'permissions' => $missing->sort()->implode(', '),
                ]),
            ]);
        }
    }

    /**
     * Users may not change their own roles or deactivate themselves.
     *
     * @param  list<string>  $newRoles
     *
     * @throws ValidationException
     */
    public function assertNotChangingOwnAccess(User $actor, User $target, array $newRoles, bool $newActive): void
    {
        if (! $actor->is($target)) {
            return;
        }

        $currentRoles = $target->getRoleNames()->sort()->values()->all();
        $sortedNew = collect($newRoles)->sort()->values()->all();

        if ($currentRoles !== $sortedNew) {
            throw ValidationException::withMessages(['roles' => __('You cannot change your own roles.')]);
        }

        if (! $newActive) {
            throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
        }
    }

    /**
     * At least one active Owner must always remain.
     *
     * @param  list<string>  $newRoles
     *
     * @throws ValidationException
     */
    public function assertKeepsAnActiveOwner(User $target, array $newRoles, bool $newActive): void
    {
        $remainsOwner = $newActive && in_array(PermissionCatalog::OWNER, $newRoles, true);

        if (! $target->isOwner() || ! $target->is_active || $remainsOwner) {
            return;
        }

        $otherActiveOwners = User::role(PermissionCatalog::OWNER)
            ->where('users.id', '!=', $target->id)
            ->where('is_active', true)
            ->count();

        if ($otherActiveOwners === 0) {
            throw ValidationException::withMessages([
                'roles' => __('This is the last active Owner. Assign another active Owner first.'),
            ]);
        }
    }

    /**
     * @param  list<string>  $newPermissions
     *
     * @throws ValidationException
     */
    public function assertCanSetRolePermissions(User $actor, Role $role, array $newPermissions): void
    {
        if ($role->name === PermissionCatalog::OWNER) {
            throw ValidationException::withMessages(['permissions' => __('The Owner role always has every permission and cannot be edited.')]);
        }

        $added = collect($newPermissions)->diff($role->permissions->pluck('name'));
        $missing = $added->diff($this->permissionsOf($actor));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => __('You cannot grant permissions you do not hold: :permissions.', [
                    'permissions' => $missing->sort()->implode(', '),
                ]),
            ]);
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function permissionsOf(User $user): Collection
    {
        /** @var Collection<int, string> $names */
        $names = $user->getAllPermissions()->pluck('name');

        return $names;
    }
}
