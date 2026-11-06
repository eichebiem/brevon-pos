<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing (FR-058–FR-060, UC-006; D-135–D-139): suppliers, purchase
 * orders, goods receipts and lots. Stock still moves only through
 * InventoryLedger; a receipt line points at the lot it created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 40)->unique();
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('payment_terms', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('po_number', 50)->unique();
            $table->string('status', 30)->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'inventory_item_id'], 'purchase_order_items_line_unique');
        });

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

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 50)->unique();
            $table->string('supplier_delivery_reference', 100)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('over_receipt_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->string('condition', 30)->default('accepted');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_lot_id')->nullable()->after('inventory_item_id')->constrained()->restrictOnDelete();
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','submitted','partially_received','received','cancelled'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_subtotal_check CHECK (subtotal >= 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_qty_check CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND unit_cost >= 0 AND line_total >= 0)');
        DB::statement('ALTER TABLE inventory_lots ADD CONSTRAINT inventory_lots_qty_check CHECK (quantity_received > 0 AND quantity_remaining >= 0 AND quantity_remaining <= quantity_received AND (unit_cost IS NULL OR unit_cost >= 0))');
        DB::statement("ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_check CHECK (quantity_received > 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND `condition` IN ('accepted','damaged','expired'))");
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_lot_id');
        });
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
