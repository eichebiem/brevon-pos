<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultOwnersSeeder;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/**
 * D-102: the three default Owners are seeded in every environment.
 * D-100, D-178: "Cashier 1", "Cashier 2" and the SM Rosales pop-up are
 * seeded only in the local environment.
 */
class AccountSeedersTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_every_environment_gets_the_three_owners_but_only_local_gets_the_cashiers_and_sm_rosales(): void
    {
        foreach (['testing', 'staging', 'production', 'local'] as $env) {
            $this->app['env'] = $env;
            // Invoke directly: `db:seed` itself asks for confirmation in production.
            $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

            $this->assertSame(['chris', 'desiree', 'harris'], User::role(PermissionCatalog::OWNER)->orderBy('username')->pluck('username')->all(), $env);
            $this->assertSame($env === 'local', User::query()->where('username', 'cashier1')->exists(), $env);
            $this->assertSame($env === 'local', User::query()->where('username', 'cashier2')->exists(), $env);
            $this->assertSame($env === 'local', Branch::query()->where('name', 'SM Rosales')->exists(), $env);
        }

        $this->assertDatabaseCount('users', 5);

        // SM Rosales: a pop-up with its own terminal, selling the main branch's doughnuts.
        $smRosales = Branch::query()->where('name', 'SM Rosales')->sole();
        $this->assertSame([LocationType::PopUp, 'POP01', null, null], [$smRosales->location_type, $smRosales->code, $smRosales->operating_starts_on, $smRosales->operating_ends_on]);
        $this->assertSame([['POS-2', 'SM Rosales POS']], Terminal::query()->where('branch_id', $smRosales->id)->get()->map(fn (Terminal $t) => [$t->terminal_code, $t->name])->all());
        $doughnuts = Product::query()->where('inventory_tracking_mode', 'finished_stock')->pluck('id')->sort()->values()->all();
        $this->assertCount(14, $doughnuts);
        $this->assertSame($doughnuts, DB::table('product_branch')->where('branch_id', $smRosales->id)->where('is_available', true)->orderBy('product_id')->pluck('product_id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'location.created', 'auditable_id' => $smRosales->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'terminal.created', 'branch_id' => $smRosales->id]);

        // Cashier 2 works at SM Rosales; Cashier 1 at the main branch.
        $this->assertSame($smRosales->id, User::query()->where('username', 'cashier2')->sole()->defaultBranch()?->id);
        $this->assertSame($this->mainLocation()->id, User::query()->where('username', 'cashier1')->sole()->defaultBranch()?->id);
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

    public function test_local_cashiers_can_sign_in_and_land_on_the_pos(): void
    {
        $this->seedAccess();
        $this->app['env'] = 'local';
        // On its own it creates SM Rosales (and the owners who audit it) first; running twice adds nothing.
        $this->seed(LocalUsersSeeder::class);
        $this->seed(LocalUsersSeeder::class);

        $this->assertSame(['cashier1', 'cashier2'], User::role(PermissionCatalog::CASHIER)->orderBy('username')->pluck('username')->all());
        $this->assertSame(1, Branch::query()->where('name', 'SM Rosales')->count());
        $this->assertSame(1, Terminal::query()->where('name', 'SM Rosales POS')->count());

        $this->app['env'] = 'testing';
        foreach (['cashier1' => 'Cashier 1', 'cashier2' => 'Cashier 2'] as $username => $name) {
            $cashier = User::query()->where('username', $username)->sole();
            $this->assertSame($name, $cashier->name);
            $this->assertSame([PermissionCatalog::CASHIER], $cashier->getRoleNames()->all());
            $this->post(route('login.store'), ['login' => $username, 'password' => 'password'])->assertRedirect('/home');
            $this->assertAuthenticatedAs($cashier);
            $this->get('/home')->assertRedirect('/pos');
            $this->post(route('logout'));
        }
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
