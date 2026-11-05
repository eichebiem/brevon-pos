<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultOwnersSeeder;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/**
 * D-102: the three default Owners are seeded in every environment.
 * D-100: "Cashier 1" is seeded only in the local environment.
 */
class AccountSeedersTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_every_environment_gets_the_three_owners_but_only_local_gets_cashier_1(): void
    {
        foreach (['testing', 'staging', 'production', 'local'] as $env) {
            $this->app['env'] = $env;
            // Invoke directly: `db:seed` itself asks for confirmation in production.
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

            $this->assertSame(['chris', 'desiree', 'harris'], User::role(PermissionCatalog::OWNER)->orderBy('username')->pluck('username')->all(), $env);
            $this->assertSame($env === 'local', User::query()->where('username', 'cashier1')->exists(), $env);
        }

        $this->assertDatabaseCount('users', 4);
    }

    public function test_owners_are_ready_to_sign_in_at_the_main_location(): void
    {
        $this->seedAccess();
        $this->seed(DefaultOwnersSeeder::class);

        foreach (['harris' => 'Harris', 'chris' => 'Chris', 'desiree' => 'Desiree'] as $username => $name) {
            $owner = User::query()->where('username', $username)->sole();
            $this->assertSame($name, $owner->name);
            $this->assertSame([PermissionCatalog::OWNER], $owner->getRoleNames()->all());
            $this->assertSame($this->mainLocation()->id, $owner->defaultBranch()?->id);
        }

        $this->post(route('login.store'), ['login' => 'desiree', 'password' => 'password'])->assertRedirect('/home');
        $this->assertAuthenticatedAs(User::query()->where('username', 'desiree')->sole());
    }

    public function test_local_cashier_can_sign_in_and_lands_on_the_pos(): void
    {
        $this->seedAccess();
        $this->app['env'] = 'local';
        $this->seed(LocalUsersSeeder::class);
        $this->seed(LocalUsersSeeder::class);

        $cashier = User::query()->where('username', 'cashier1')->sole();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Cashier 1', $cashier->name);
        $this->assertSame([PermissionCatalog::CASHIER], $cashier->getRoleNames()->all());

        $this->app['env'] = 'testing';
        $this->post(route('login.store'), ['login' => 'cashier1', 'password' => 'password'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($cashier);
        $this->get('/home')->assertRedirect('/pos');
    }

    public function test_reseeding_keeps_a_password_the_owner_changed(): void
    {
        $this->seedAccess();
        $this->seed(DefaultOwnersSeeder::class);

        $owner = User::query()->where('username', 'chris')->sole();
        $owner->forceFill(['password' => 'My-Own-Secret-99'])->save();

        $this->seed(DefaultOwnersSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertTrue(Hash::check('My-Own-Secret-99', (string) $owner->refresh()->password));
    }
}
