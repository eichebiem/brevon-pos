<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** Prompt §5, D-022: local-only owner bootstrap with no known password. */
class CreateOwnerCommandTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_requires_seeded_roles_and_location(): void
    {
        config(['brevon.dev_owner' => ['email' => 'owner@example.test', 'password' => 'Long-Enough-123']]);

        $this->artisan('brevon:create-owner', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_creates_an_audited_owner_from_configuration(): void
    {
        $this->seedAccess();
        config(['brevon.dev_owner' => ['email' => 'owner@example.test', 'password' => 'Long-Enough-123']]);

        $this->artisan('brevon:create-owner', ['--name' => 'Local Owner', '--username' => 'Owner', '--no-interaction' => true])->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole(PermissionCatalog::OWNER));
        $this->assertSame('owner', $user->username);
        $this->assertSame($this->mainLocation()->id, $user->defaultBranch()?->id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'auditable_id' => $user->id]);
    }

    public function test_requires_a_username(): void
    {
        $this->seedAccess();
        config(['brevon.dev_owner' => ['email' => 'owner@example.test', 'password' => 'Long-Enough-123']]);

        $this->artisan('brevon:create-owner', ['--name' => 'Local Owner', '--no-interaction' => true])
            ->expectsOutputToContain('username')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_rejects_short_passwords(): void
    {
        $this->seedAccess();
        config(['brevon.dev_owner' => ['email' => 'owner@example.test', 'password' => 'short1']]);

        $this->artisan('brevon:create-owner', ['--username' => 'owner', '--no-interaction' => true])
            ->expectsOutputToContain('password')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->seedAccess();
        config(['brevon.dev_owner' => ['email' => 'owner@example.test', 'password' => 'Long-Enough-123']]);
        $this->app['env'] = 'production';

        $this->artisan('brevon:create-owner', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
