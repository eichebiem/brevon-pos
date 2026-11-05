<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** D-084, D-100: the known-password accounts exist only in the local environment. */
class LocalUsersSeederTest extends TestCase
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

    public function test_local_seeding_creates_the_owner_and_cashier_idempotently(): void
    {
        $this->seedAccess();
        $this->app['env'] = 'local';

        $this->seed(LocalUsersSeeder::class);
        $this->seed(LocalUsersSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $owner = User::query()->where('username', 'harris')->sole();
        $cashier = User::query()->where('username', 'cashier1')->sole();
        $this->assertSame('Harris', $owner->name);
        $this->assertTrue($owner->hasRole(PermissionCatalog::OWNER));
        $this->assertSame('Cashier 1', $cashier->name);
        $this->assertSame([PermissionCatalog::CASHIER], $cashier->getRoleNames()->all());
        $this->assertSame($this->mainLocation()->id, $cashier->defaultBranch()?->id);

        $this->app['env'] = 'testing';
        $this->post(route('login.store'), ['login' => 'cashier1', 'password' => 'password'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($cashier);
        $this->get('/home')->assertRedirect('/pos');
    }

    public function test_reseeding_keeps_a_password_the_user_changed(): void
    {
        $this->seedAccess();
        $this->app['env'] = 'local';
        $this->seed(LocalUsersSeeder::class);

        $owner = User::query()->where('username', 'harris')->sole();
        $owner->forceFill(['password' => 'My-Own-Secret-99'])->save();

        $this->seed(LocalUsersSeeder::class);

        $this->assertTrue(Hash::check('My-Own-Secret-99', (string) $owner->refresh()->password));
    }
}
