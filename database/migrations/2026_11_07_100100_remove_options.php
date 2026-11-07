<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes options & add-ons (client 2026-11-06; D-166). Product sizes
 * (variants) stay.
 *
 * Drops `order_item_options`, `product_option_group`, `product_options`,
 * `option_groups` and `order_items.option_total`. The migration refuses to
 * run if any sale carried an add-on, so recorded sales never change; option
 * groups that were set up but never sold are dropped with the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sold = DB::table('order_item_options')->count() + DB::table('order_items')->where('option_total', '<>', 0)->count();
        if ($sold > 0) {
            throw new RuntimeException("Add-ons were sold on {$sold} order rows; review them before removing options (D-166).");
        }

        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('product_option_group');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('option_groups');

        DB::statement('ALTER TABLE order_items DROP CHECK order_items_amounts_check');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('option_total'));
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CHECK order_items_amounts_check');
        Schema::table('order_items', fn (Blueprint $table) => $table->decimal('option_total', 12, 2)->default(0)->after('unit_price'));
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND option_total >= 0 AND discount_amount >= 0 AND line_total >= 0)');

        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('selection_type', 20)->default('single');
            $table->unsignedInteger('min_selections')->default(0);
            $table->unsignedInteger('max_selections')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE option_groups ADD CONSTRAINT option_groups_type_check CHECK (selection_type IN ('single', 'multiple'))");
        DB::statement('ALTER TABLE option_groups ADD CONSTRAINT option_groups_range_check CHECK (max_selections IS NULL OR max_selections >= min_selections)');
        DB::statement("ALTER TABLE option_groups ADD CONSTRAINT option_groups_single_check CHECK (selection_type <> 'single' OR (max_selections = 1 AND min_selections <= 1))");

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['option_group_id', 'name']);
        });
        DB::statement('ALTER TABLE product_options ADD CONSTRAINT product_options_price_check CHECK (price_delta >= 0)');

        Schema::create('product_option_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'option_group_id']);
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('product_options')->restrictOnDelete();
            $table->string('option_group_name_snapshot', 100);
            $table->string('option_name_snapshot', 100);
            $table->decimal('price_delta_snapshot', 12, 2)->default(0);
            $table->timestamps();
        });
    }
};
