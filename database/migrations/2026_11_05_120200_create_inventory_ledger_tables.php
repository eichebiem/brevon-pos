<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory ledger (FR-054, FR-055, BR-011; D-051). inventory_movements is the
 * append-only source of truth; branch_inventory is the per-location balance
 * projection and must always equal the sum of movements. Both are written only
 * by App\Domain\Inventory\InventoryLedger. inventory_lot_id arrives with lots
 * in Phase 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('average_unit_cost', 14, 4)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['branch_id', 'inventory_item_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('balance_after', 14, 3);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'inventory_item_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        $types = "'opening','purchase_receipt','production_consumption','production_output','sale','waste','adjustment_in','adjustment_out','return_in','transfer_in','transfer_out'";
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check CHECK (movement_type IN ({$types}))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_delta_check CHECK (quantity_delta <> 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_cost_check CHECK (unit_cost IS NULL OR unit_cost >= 0)');
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_cost_check CHECK (average_unit_cost IS NULL OR average_unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('branch_inventory');
    }
};
