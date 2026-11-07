<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes preparation and the Cancel action (client 2026-11-06; D-169,
 * replacing D-123 and D-125). Every sale is completed at checkout and every
 * correction is a refund.
 *
 * The old statuses and the cancellation columns stay so older orders and
 * their void records still read correctly. The migration refuses to run
 * while any order is still Pending, New, Preparing or Ready: finish or
 * cancel those on the old screens first. It removes the
 * `orders.update-status` and `orders.cancel` permissions, and drops the
 * pickup time only when no order has one (otherwise the column is kept,
 * unused, so no data is lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = DB::table('orders')->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])->count();
        if ($open > 0) {
            throw new RuntimeException("{$open} order(s) are still in progress; complete or cancel them before removing preparation (D-169).");
        }

        foreach (['orders.update-status', 'orders.cancel'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        app('cache')->forget(config('permission.cache.key'));

        if (Schema::hasColumn('orders', 'pickup_at') && ! DB::table('orders')->whereNotNull('pickup_at')->exists()) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('pickup_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'pickup_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dateTime('pickup_at')->nullable()->after('notes');
            });
        }
        // The permissions come back with RolesAndPermissionsSeeder if preparation is restored.
    }
};
