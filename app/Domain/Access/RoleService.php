<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role administration (FR-006, FR-010) with audit history (FR-098).
 */
class RoleService
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function create(User $actor, string $name, array $permissions): Role
    {
        $role = new Role(['name' => $name, 'guard_name' => 'web']);
        $role->setRelation('permissions', collect());
        $this->guard->assertCanSetRolePermissions($actor, $role, $permissions);

        return DB::transaction(function () use ($actor, $name, $permissions) {
            $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->audit->record('role.created', $role, null, ['name' => $name, 'permissions' => $this->sorted($permissions)], actor: $actor);

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    public function update(User $actor, Role $role, string $name, array $permissions): Role
    {
        $role->load('permissions');
        $this->guard->assertCanSetRolePermissions($actor, $role, $permissions);

        if ($name !== $role->name && in_array($role->name, AccessGuard::SYSTEM_ROLES, true)) {
            throw ValidationException::withMessages(['name' => __('Built-in roles cannot be renamed.')]);
        }

        return DB::transaction(function () use ($actor, $role, $name, $permissions) {
            $old = ['name' => $role->name, 'permissions' => $this->sorted($role->permissions->pluck('name')->all())];

            $role->update(['name' => $name]);
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $new = ['name' => $name, 'permissions' => $this->sorted($permissions)];
            $removed = array_values(array_diff($old['permissions'], $new['permissions']));
            $added = array_values(array_diff($new['permissions'], $old['permissions']));

            if ($old['name'] !== $new['name'] || $removed !== [] || $added !== []) {
                $this->audit->record('role.updated', $role,
                    ['name' => $old['name'], 'permissions_removed' => $removed],
                    ['name' => $new['name'], 'permissions_added' => $added],
                    actor: $actor,
                );
            }

            return $role;
        });
    }

    public function delete(User $actor, Role $role): void
    {
        if (in_array($role->name, AccessGuard::SYSTEM_ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('Built-in roles cannot be deleted.')]);
        }

        if (User::role($role->name)->withTrashed()->exists()) {
            throw ValidationException::withMessages(['role' => __('Remove this role from all users before deleting it.')]);
        }

        DB::transaction(function () use ($actor, $role) {
            $this->audit->record('role.deleted', $role, ['name' => $role->name, 'permissions' => $this->sorted($role->permissions->pluck('name')->all())], null, actor: $actor);
            $role->delete();
        });
    }

    /**
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private function sorted(array $permissions): array
    {
        $sorted = array_values($permissions);
        sort($sorted);

        return $sorted;
    }
}
