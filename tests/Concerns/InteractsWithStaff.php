<?php

namespace Tests\Concerns;

use App\Domain\Access\PermissionCatalog;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the real role matrix and permanent location, and builds staff
 * accounts holding a given role (no hand-made permission lists in tests).
 */
trait InteractsWithStaff
{
    protected function seedAccess(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, LocationSeeder::class]);
    }

    protected function mainLocation(): Branch
    {
        return Branch::query()->where('code', 'BRV01')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function staff(string $role, ?Branch $location = null, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->branches()->attach(($location ?? $this->mainLocation())->id, ['is_default' => true]);

        return $user;
    }

    /**
     * A custom role an owner might make for bakers: production drafts and
     * read-only stock, like the removed Preparation Staff role (D-176).
     */
    protected function bakerRole(): string
    {
        return $this->customRole('Baker', ['orders.view', 'products.view', 'inventory.view', 'production.create']);
    }

    /** A custom office role with everything but role management, like the removed Admin role (D-176). */
    protected function officeRole(): string
    {
        return $this->customRole('Office', array_values(array_diff(PermissionCatalog::all(), ['roles.manage'])));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function customRole(string $name, array $permissions): string
    {
        Role::findOrCreate($name, 'web')->syncPermissions($permissions);

        return $name;
    }
}
