<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent. Creates missing permissions and roles. Existing roles keep any
 * customisation, except Owner, which always holds every permission.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::defaultRoles() as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                Role::create(['name' => $roleName, 'guard_name' => 'web'])->syncPermissions($permissions);
            } elseif ($roleName === PermissionCatalog::OWNER) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
