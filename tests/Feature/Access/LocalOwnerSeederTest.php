<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalOwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** D-084: the known-password Owner exists only in the local environment. */
class LocalOwnerSeederTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_no_account_is_seeded_outside_local(): void
    {
        foreach (['testing', 'staging', 'production'] as $env) {
            $this->app['env'] = $env;
            // Invoke directly: `db:seed` itself asks for confirmation in production.
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_local_seeding_creates_an_owner_idempotently(): void
    {
        $this->seedAccess();
        $this->app['env'] = 'local';

        $this->seed(LocalOwnerSeeder::class);
        $this->seed(LocalOwnerSeeder::class);

        $user = User::query()->where('username', LocalOwnerSeeder::USERNAME)->sole();
        $this->assertSame('Harris', $user->name);
        $this->assertTrue($user->hasRole(PermissionCatalog::OWNER));
        $this->assertSame($this->mainLocation()->id, $user->defaultBranch()?->id);

        $this->app['env'] = 'testing';
        $this->post(route('login.store'), ['login' => 'harris', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }
}
