<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a stock control (SRS §8.7; FR-056, FR-061, BR-012; D-063,
 * D-131–D-134): adjustments and counts that wait for approval, and the
 * main → pop-up doughnut dispatch. Stock itself only changes through
 * inventory_movements, written by the ledger when a document is posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('adjustment_number', 50)->unique();
            // (+) count = whole-count correction; change = add or remove a quantity (D-131).
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_kind_check CHECK (kind IN ('count', 'change'))");
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_status_check CHECK (status IN ('pending', 'posted', 'rejected'))");

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3);
            $table->decimal('variance_quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_adjustment_id', 'inventory_item_id'], 'stock_adjustment_items_line_unique');
        });
        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT stock_adjustment_items_variance_check CHECK (variance_quantity = counted_quantity - system_quantity AND counted_quantity >= 0)');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 50)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at');
            $table->timestamps();

            $table->index(['to_branch_id', 'posted_at']);
            $table->index(['from_branch_id', 'posted_at']);
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_branches_check CHECK (from_branch_id <> to_branch_id)');

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'inventory_item_id'], 'stock_transfer_items_line_unique');
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
