<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the tax feature (client 2026-11-06: non-VAT business; D-165).
 *
 * Drops `order_tax_lines`, `tax_rules`, `orders.tax_total` and
 * `order_items.tax_amount`. Tax was never switched on, so every amount is
 * 0.00; the migration refuses to run if any tax was ever charged, so no
 * figure is lost without someone looking at it first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $charged = DB::table('order_tax_lines')->count()
            + DB::table('orders')->where('tax_total', '<>', 0)->count()
            + DB::table('order_items')->where('tax_amount', '<>', 0)->count();
        if ($charged > 0) {
            throw new RuntimeException("Tax was charged on {$charged} order rows; review them before removing tax (D-165).");
        }

        Schema::dropIfExists('order_tax_lines');
        Schema::dropIfExists('tax_rules');

        DB::statement('ALTER TABLE orders DROP CHECK orders_money_check');
        DB::statement('ALTER TABLE order_items DROP CHECK order_items_amounts_check');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('tax_total'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal >= 0 AND discount_total >= 0 AND service_charge >= 0 AND grand_total >= 0 AND amount_paid >= 0 AND amount_due >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND option_total >= 0 AND discount_amount >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_money_check');
        DB::statement('ALTER TABLE order_items DROP CHECK order_items_amounts_check');
        Schema::table('orders', fn (Blueprint $table) => $table->decimal('tax_total', 12, 2)->default(0)->after('discount_total'));
        Schema::table('order_items', fn (Blueprint $table) => $table->decimal('tax_amount', 12, 2)->default(0)->after('discount_amount'));
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND service_charge >= 0 AND grand_total >= 0 AND amount_paid >= 0 AND amount_due >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND option_total >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->nullable();
            $table->decimal('rate', 7, 4);
            $table->string('calculation_method', 30)->default('percentage');
            $table->boolean('inclusive')->default(false);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('order_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_name_snapshot', 100);
            $table->decimal('tax_rate_snapshot', 7, 4);
            $table->boolean('inclusive_snapshot');
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->timestamps();

            $table->index('order_id');
        });
    }
};
