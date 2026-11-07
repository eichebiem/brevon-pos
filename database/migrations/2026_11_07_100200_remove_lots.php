<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes lots & expiry (client 2026-11-06; D-167, replacing D-138).
 *
 * Drops the two `inventory_lot_id` links, `inventory_items.track_lot` and
 * `inventory_lots`. Stock quantities and costs live on the ledger and are
 * not touched; only lot numbers and expiry dates recorded on deliveries
 * are lost, which the client accepted. Kept: `is_perishable`, rejecting a
 * delivery line as expired, and writing off expired stock as waste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_lot_id'));
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_lot_id'));
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('track_lot'));
        Schema::dropIfExists('inventory_lots');
    }

    public function down(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 80)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('quantity_remaining', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'inventory_item_id', 'expires_at'], 'inventory_lots_fefo_index');
        });
        Schema::table('inventory_items', fn (Blueprint $table) => $table->boolean('track_lot')->default(false)->after('is_perishable'));
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->foreignId('inventory_lot_id')->nullable()->after('inventory_item_id')->constrained()->restrictOnDelete());
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->foreignId('inventory_lot_id')->nullable()->after('inventory_item_id')->constrained()->restrictOnDelete());
    }
};
