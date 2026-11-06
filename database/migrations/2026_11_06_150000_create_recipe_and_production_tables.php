<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes and production (FR-064–FR-072; D-140–D-144). Recipes are
 * versioned and never edited; a production batch consumes ingredients and
 * adds finished stock in one InventoryLedger call. A recipe may be for one
 * size (variant) of a product — a schema addition for drinks whose sizes use
 * different amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            // 0 = every size; used only for the unique version number.
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(product_variant_id, 0)');
            $table->unsignedInteger('version');
            $table->decimal('output_quantity', 14, 3)->default(1);
            $table->string('output_unit', 30)->default('piece');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'variant_key', 'version'], 'recipes_version_unique');
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_required', 14, 3);
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('wastage_percent', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'inventory_item_id'], 'recipe_items_line_unique');
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 50)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 14, 3);
            $table->decimal('actual_quantity', 14, 3);
            $table->decimal('rejected_quantity', 14, 3)->default(0);
            $table->text('reject_reason')->nullable();
            $table->timestamp('produced_at');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('production_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_consumed', 14, 3);
            $table->decimal('unit_cost_snapshot', 14, 4)->nullable();
            $table->timestamps();

            $table->unique(['production_batch_id', 'inventory_item_id'], 'production_batch_items_line_unique');
        });

        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_check CHECK (version > 0 AND output_quantity > 0)');
        DB::statement('ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_check CHECK (quantity_required > 0 AND wastage_percent >= 0 AND wastage_percent <= 100)');
        DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_check CHECK (status IN ('draft','posted','cancelled') AND planned_quantity > 0 AND actual_quantity >= 0 AND rejected_quantity >= 0 AND actual_quantity + rejected_quantity > 0 AND (unit_cost IS NULL OR unit_cost >= 0))");
        DB::statement('ALTER TABLE production_batch_items ADD CONSTRAINT production_batch_items_check CHECK (quantity_consumed >= 0 AND (unit_cost_snapshot IS NULL OR unit_cost_snapshot >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_items');
        Schema::dropIfExists('production_batches');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
