<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three built-in roles (user request 2026-11-07, D-176): Admin is merged into
 * Owner, and Preparation Staff is removed.
 *
 * Everyone who held Admin is given Owner, then the Admin role is deleted.
 * Preparation Staff is deleted only when nobody holds it: the migration
 * refuses to run otherwise, so no account is left without a role — give those
 * users another role on the Users screen first. Custom roles are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prep = DB::table('roles')->where('name', 'Preparation Staff')->where('guard_name', 'web')->value('id');
        if ($prep !== null) {
            $holders = DB::table('model_has_roles')->where('role_id', $prep)->count();
            if ($holders > 0) {
                throw new RuntimeException("{$holders} user(s) still have the Preparation Staff role; give them another role before removing it (D-176).");
            }
        }

        DB::transaction(function () use ($prep) {
            $owner = DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->value('id');
            $admin = DB::table('roles')->where('name', 'Admin')->where('guard_name', 'web')->value('id');

            if ($admin !== null) {
                if ($owner !== null) {
                    $admins = DB::table('model_has_roles')->where('role_id', $admin)->get(['model_type', 'model_id']);
                    foreach ($admins as $row) {
                        DB::table('model_has_roles')->insertOrIgnore(['role_id' => $owner, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
                    }
                }
                $this->deleteRole((int) $admin);
            }

            if ($prep !== null) {
                $this->deleteRole((int) $prep);
            }
        });

        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // The roles come back with RolesAndPermissionsSeeder if they are restored in code.
    }

    private function deleteRole(int $id): void
    {
        DB::table('role_has_permissions')->where('role_id', $id)->delete();
        DB::table('model_has_roles')->where('role_id', $id)->delete();
        DB::table('roles')->where('id', $id)->delete();
    }
};
