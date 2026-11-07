<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the separate GCash check (client 2026-11-06; D-168, replacing
 * D-124). GCash stays; the cashier confirms the transfer before finishing
 * the sale and the payment is saved as verified.
 *
 * No table changes: the `pending` and `failed` payment statuses stay valid
 * for history. The migration refuses to run while any payment is still
 * waiting to be checked — check them on the old screen first — and then
 * removes the `payments.verify-ewallet` permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $waiting = DB::table('payments')->where('status', 'pending')->count();
        if ($waiting > 0) {
            throw new RuntimeException("{$waiting} GCash payment(s) are still waiting to be checked; check them before removing the GCash check (D-168).");
        }

        $id = DB::table('permissions')->where('name', 'payments.verify-ewallet')->value('id');
        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // The permission comes back with RolesAndPermissionsSeeder if the GCash check is restored.
    }
};
