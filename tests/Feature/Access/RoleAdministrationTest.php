<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-006, FR-010 · T-RBAC-11..15 */
class RoleAdministrationTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_seeded_role_matrix(): void
    {
        $counts = Role::query()->withCount('permissions')->pluck('permissions_count', 'name')->all();

        $this->assertSame(count(PermissionCatalog::all()), $counts[PermissionCatalog::OWNER]);
        foreach (PermissionCatalog::defaultRoles() as $role => $permissions) {
            $this->assertSame(count($permissions), $counts[$role], $role);
        }
        // D-176: three built-in roles; Owner absorbed Admin, Preparation Staff is gone.
        $this->assertEqualsCanonicalizing([PermissionCatalog::OWNER, PermissionCatalog::MANAGER, PermissionCatalog::CASHIER], array_keys($counts));
    }

    public function test_the_role_migration_moves_admins_to_owner_and_refuses_while_preparation_staff_is_in_use(): void
    {
        $migration = require database_path('migrations/2026_11_07_100500_merge_admin_into_owner_and_remove_preparation_staff.php');
        Role::create(['name' => 'Admin', 'guard_name' => 'web'])->givePermissionTo('users.manage');
        Role::create(['name' => 'Preparation Staff', 'guard_name' => 'web']);
        $admin = $this->staff('Admin');
        $baker = $this->staff('Preparation Staff');

        try {
            $migration->up();
            $this->fail('Expected the migration to refuse while someone has Preparation Staff.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preparation Staff', $e->getMessage());
        }
        $this->assertTrue(Role::query()->where('name', 'Admin')->exists(), 'nothing changed');

        $baker->syncRoles([PermissionCatalog::CASHIER]);
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([PermissionCatalog::OWNER], $admin->refresh()->getRoleNames()->all());
        $this->assertFalse(Role::query()->whereIn('name', ['Admin', 'Preparation Staff'])->exists());
        $this->assertSame([PermissionCatalog::CASHIER], $baker->refresh()->getRoleNames()->all());
    }

    public function test_roles_are_listed_owner_manager_cashier_then_by_creation(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        // Built-in roles go first even on a database where they were made in another order.
        Role::findByName(PermissionCatalog::OWNER)->forceFill(['created_at' => now()->addDay()])->save();
        Role::findByName(PermissionCatalog::CASHIER)->forceFill(['created_at' => now()->subYear()])->save();
        Role::create(['name' => 'Baker'])->forceFill(['created_at' => now()->subDay()])->save();
        Role::create(['name' => 'Accountant'])->forceFill(['created_at' => now()])->save();
        $both = $this->staff(PermissionCatalog::CASHIER);
        $both->assignRole('Accountant', PermissionCatalog::MANAGER);

        $expected = [PermissionCatalog::OWNER, PermissionCatalog::MANAGER, PermissionCatalog::CASHIER, 'Baker', 'Accountant'];
        $this->actingAs($owner)->get('/admin/roles')->assertOk()
            ->assertInertia(fn ($page) => $page->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === $expected));
        $this->actingAs($owner)->get('/admin/users/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('roles', $expected));
        $this->actingAs($owner)->get('/admin/users')->assertOk()
            ->assertInertia(fn ($page) => $page->where('roles', $expected)
                ->where('users.data', fn ($users) => collect($users)->firstWhere('id', $both->id)['roles'] === [PermissionCatalog::MANAGER, PermissionCatalog::CASHIER, 'Accountant']));
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $before = Role::query()->withCount('permissions')->pluck('permissions_count', 'name')->all();

        $this->seedAccess();

        $this->assertSame($before, Role::query()->withCount('permissions')->pluck('permissions_count', 'name')->all());
    }

    public function test_owner_creates_a_custom_role_and_it_is_audited(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($owner)->post('/admin/roles', ['name' => 'Shift Lead', 'permissions' => ['pos.access', 'shifts.review']])
            ->assertRedirect('/admin/roles');

        $role = Role::findByName('Shift Lead');
        $this->assertEqualsCanonicalizing(['pos.access', 'shifts.review'], $role->permissions->pluck('name')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.created', 'auditable_type' => 'role', 'auditable_id' => $role->id, 'user_id' => $owner->id]);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($owner)->post('/admin/roles', ['name' => 'Bad', 'permissions' => ['does.not-exist']])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_the_owner_role_cannot_be_edited_or_deleted(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $role = Role::findByName(PermissionCatalog::OWNER);

        $this->actingAs($owner)->put("/admin/roles/{$role->id}", ['name' => 'Owner', 'permissions' => ['pos.access']])
            ->assertSessionHasErrors('permissions');
        $this->actingAs($owner)->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $this->assertSame(count(PermissionCatalog::all()), $role->permissions()->count());
    }

    public function test_built_in_roles_keep_their_name_but_permissions_can_change(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $cashier = Role::findByName(PermissionCatalog::CASHIER);

        $this->actingAs($owner)->put("/admin/roles/{$cashier->id}", ['name' => 'Till', 'permissions' => ['pos.access']])
            ->assertSessionHasErrors('name');

        $this->actingAs($owner)->put("/admin/roles/{$cashier->id}", ['name' => PermissionCatalog::CASHIER, 'permissions' => ['pos.access', 'pos.create-sale']])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $cashier->permissions()->count());

        $log = AuditLog::query()->where('event', 'role.updated')->firstOrFail();
        $this->assertNotEmpty($log->old_values);
    }

    public function test_a_role_editor_cannot_grant_permissions_they_do_not_hold(): void
    {
        $editor = Role::query()->create(['name' => 'Role Editor', 'guard_name' => 'web']);
        $editor->givePermissionTo('roles.manage', 'pos.access');
        $user = $this->staff('Role Editor');

        $this->actingAs($user)->post('/admin/roles', ['name' => 'Sneaky', 'permissions' => ['pos.access', 'users.manage']])
            ->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky']);
    }

    public function test_roles_in_use_and_built_in_roles_cannot_be_deleted(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $custom = Role::query()->create(['name' => 'Temp', 'guard_name' => 'web']);
        $holder = $this->staff('Temp');

        $this->actingAs($owner)->delete('/admin/roles/'.Role::findByName(PermissionCatalog::CASHIER)->id)->assertSessionHasErrors('role');
        $this->actingAs($owner)->delete("/admin/roles/{$custom->id}")->assertSessionHasErrors('role');

        $holder->syncRoles([]);
        $this->actingAs($owner)->delete("/admin/roles/{$custom->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('roles', ['name' => 'Temp']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.deleted', 'auditable_id' => $custom->id]);
    }
}
