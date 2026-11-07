<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units and a minimal inventory item master, needed now for the
 * product → finished-good stock link (D-025). Balances, movements and the
 * inventory UI arrive in Phases 4a and 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->unsignedTinyInteger('precision')->default(3);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('name', 150);
            $table->string('item_type', 30);
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->boolean('is_perishable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_type', 'is_active']);
        });

        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_type_check CHECK (item_type IN ('ingredient', 'packaging', 'finished_good', 'retail'))");
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_reorder_check CHECK (reorder_level >= 0)');
        DB::statement('ALTER TABLE units_of_measure ADD CONSTRAINT units_precision_check CHECK (`precision` <= 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('units_of_measure');
    }
};
