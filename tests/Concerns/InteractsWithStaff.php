<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

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
}
