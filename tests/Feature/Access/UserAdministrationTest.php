<?php

namespace Tests\Feature\Access;

use App\Domain\Access\AccessGuard;
use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-005, FR-006, FR-010, FR-098 · T-RBAC-4..10 */
class UserAdministrationTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ana Cruz',
            'username' => 'Ana.Cruz',
            'email' => 'ana@example.test',
            'password' => 'Correct-Horse-42',
            'password_confirmation' => 'Correct-Horse-42',
            'is_active' => true,
            'roles' => [PermissionCatalog::CASHIER],
            'branch_ids' => [$this->mainLocation()->id],
            'default_branch_id' => $this->mainLocation()->id,
            'has_employee_profile' => true,
            'employee' => ['employee_code' => 'E-001', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'middle_name' => '', 'phone' => '', 'hire_date' => '2026-11-01'],
            ...$overrides,
        ];
    }

    public function test_admin_creates_a_cashier_with_location_and_profile_and_it_is_audited(): void
    {
        $admin = $this->staff($this->officeRole());

        $this->actingAs($admin)->post('/admin/users', $this->payload())->assertRedirect('/admin/users');

        $user = User::query()->where('email', 'ana@example.test')->firstOrFail();
        $this->assertSame('ana.cruz', $user->username);
        $this->assertTrue($user->hasRole(PermissionCatalog::CASHIER));
        $this->assertTrue(Hash::check('Correct-Horse-42', $user->password));
        $this->assertSame('E-001', $user->employee?->employee_code);
        $this->assertNull($user->employee?->middle_name);
        $this->assertDatabaseHas('user_branch', ['user_id' => $user->id, 'branch_id' => $this->mainLocation()->id, 'is_default' => true]);

        $log = AuditLog::query()->where('event', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertArrayNotHasKey('password', $log->new_values ?? []);
        $this->assertStringNotContainsString('Correct-Horse-42', json_encode($log->new_values) ?: '');
    }

    public function test_every_account_needs_a_username(): void
    {
        $admin = $this->staff($this->officeRole());

        foreach (['', '   ', 'ab', 'Ana Cruz', '.ana'] as $bad) {
            $this->actingAs($admin)->post('/admin/users', $this->payload(['username' => $bad]))->assertSessionHasErrors('username');
        }

        $existing = $this->staff(PermissionCatalog::CASHIER);
        $this->actingAs($admin)->put("/admin/users/{$existing->id}", $this->payload(['email' => $existing->email, 'username' => '', 'password' => '', 'password_confirmation' => '', 'has_employee_profile' => false]))
            ->assertSessionHasErrors('username');

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.test']);
    }

    public function test_a_user_needs_at_least_one_role_and_one_location(): void
    {
        $admin = $this->staff($this->officeRole());

        $this->actingAs($admin)->post('/admin/users', $this->payload(['roles' => [], 'branch_ids' => []]))
            ->assertSessionHasErrors(['roles', 'branch_ids']);
    }

    public function test_default_location_must_be_one_of_the_assigned_locations(): void
    {
        $admin = $this->staff($this->officeRole());
        $popUp = Branch::factory()->popUp()->create();

        $this->actingAs($admin)->post('/admin/users', $this->payload(['default_branch_id' => $popUp->id]))
            ->assertSessionHasErrors('default_branch_id');
    }

    public function test_admin_cannot_assign_the_owner_role(): void
    {
        $admin = $this->staff($this->officeRole());

        $this->actingAs($admin)->post('/admin/users', $this->payload(['roles' => [PermissionCatalog::OWNER]]))
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.test']);
    }

    public function test_cannot_assign_a_role_with_permissions_the_actor_lacks(): void
    {
        $limited = Role::query()->create(['name' => 'HR Clerk', 'guard_name' => 'web']);
        $limited->givePermissionTo('users.manage', 'pos.access');
        $clerk = $this->staff('HR Clerk');

        $this->actingAs($clerk)->post('/admin/users', $this->payload(['roles' => [PermissionCatalog::MANAGER]]))
            ->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.test']);
    }

    public function test_admin_cannot_edit_or_deactivate_an_owner(): void
    {
        $admin = $this->staff($this->officeRole());
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($admin)->get("/admin/users/{$owner->id}/edit")->assertForbidden();
        $this->actingAs($admin)->patch("/admin/users/{$owner->id}/status", ['is_active' => false])->assertForbidden();
        $this->assertTrue($owner->refresh()->is_active);
    }

    public function test_users_cannot_change_their_own_roles_or_deactivate_themselves(): void
    {
        $admin = $this->staff($this->officeRole(), attributes: ['email' => 'me@example.test']);
        $base = $this->payload(['email' => 'me@example.test', 'username' => $admin->username, 'password' => '', 'password_confirmation' => '', 'has_employee_profile' => false, 'roles' => ['Office']]);

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [...$base, 'roles' => [PermissionCatalog::MANAGER]])
            ->assertSessionHasErrors('roles');
        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->refresh()->is_active);
        $this->assertTrue($admin->hasRole('Office'));
    }

    public function test_an_owner_can_deactivate_another_owner_while_one_active_owner_remains(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $second = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($owner)->patch("/admin/users/{$second->id}/status", ['is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($second->refresh()->is_active);
    }

    /**
     * Over HTTP the self-change rule fires first (only an active Owner can
     * reach an Owner), so the last-owner rule is checked directly as a
     * second line of defence.
     */
    public function test_last_active_owner_guard_refuses_demotion_and_deactivation(): void
    {
        $guard = $this->app->make(AccessGuard::class);
        $owner = $this->staff(PermissionCatalog::OWNER);
        $this->staff(PermissionCatalog::OWNER, attributes: ['is_active' => false]);

        foreach ([[[PermissionCatalog::MANAGER], true], [[PermissionCatalog::OWNER], false]] as [$roles, $active]) {
            try {
                $guard->assertKeepsAnActiveOwner($owner, $roles, $active);
                $this->fail('Expected the last active Owner to be protected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('roles', $e->errors());
            }
        }

        $this->staff(PermissionCatalog::OWNER);
        $guard->assertKeepsAnActiveOwner($owner, [PermissionCatalog::MANAGER], true);
        $this->addToAssertionCount(1);
    }

    public function test_deactivation_is_audited_and_ends_the_session(): void
    {
        $admin = $this->staff($this->officeRole());
        $cashier = $this->staff(PermissionCatalog::CASHIER);

        $this->actingAs($admin)->patch("/admin/users/{$cashier->id}/status", ['is_active' => false])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', 'user.deactivated')->firstOrFail();
        $this->assertSame(['is_active' => true], $log->old_values);
        $this->assertSame(['is_active' => false], $log->new_values);

        // A fresh request loads the account from the database.
        $this->actingAs($cashier->refresh())->get('/pos')->assertRedirect(route('login'));
    }

    public function test_password_reset_by_admin_is_audited_without_the_password(): void
    {
        $admin = $this->staff($this->officeRole());
        $cashier = $this->staff(PermissionCatalog::CASHIER, attributes: ['email' => 'c@example.test', 'username' => 'cash1']);

        $this->actingAs($admin)->put("/admin/users/{$cashier->id}", $this->payload([
            'email' => 'c@example.test', 'username' => 'cash1', 'has_employee_profile' => false,
            'password' => 'New-Password-77', 'password_confirmation' => 'New-Password-77',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('New-Password-77', $cashier->refresh()->password));
        $log = AuditLog::query()->where('event', 'user.updated')->latest('id')->firstOrFail();
        $this->assertSame('[redacted]', $log->new_values['password'] ?? null);
        $this->assertStringNotContainsString('New-Password-77', json_encode($log->new_values) ?: '');
    }

    public function test_user_list_filters_by_role_and_status(): void
    {
        $admin = $this->staff($this->officeRole());
        $this->staff(PermissionCatalog::CASHIER, attributes: ['name' => 'Active Cashier']);
        $this->staff(PermissionCatalog::CASHIER, attributes: ['name' => 'Old Cashier', 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/users?role=Cashier&status=inactive')
            ->assertInertia(fn ($page) => $page->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Old Cashier'));
    }
}
