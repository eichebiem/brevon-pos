<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, items, status history and payments (SRS §8.4; FR-022, FR-030,
 * FR-031, FR-035, FR-037; D-026, D-067, D-102..D-110). No tax (D-165), no
 * add-ons (D-166) and no pickup time (D-169, D-171): every sale is completed
 * and paid at checkout. Order types: Dine-in, Takeout, Advance Order (D-181).
 *
 * No customer columns (D-067). Location, terminal and shift come from the
 * cashier's open shift. Every FK is RESTRICT and the models refuse delete():
 * completed sales and payments are never physically deleted (BR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('order_number', 50);
            $table->date('business_date');
            $table->string('order_type', 30);
            $table->string('status', 30);
            $table->string('payment_status', 30)->default('unpaid');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('idempotency_request_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'order_number']);
            $table->unique(['branch_id', 'idempotency_key']);
            $table->index(['branch_id', 'business_date', 'status']);
            $table->index('cashier_shift_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('sku_snapshot', 60);
            $table->string('product_name_snapshot', 150);
            $table->string('variant_name_snapshot', 100)->nullable();
            $table->string('product_type_snapshot', 30);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->string('reference_number', 120)->nullable();
            $table->string('status', 30);
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            // A reference number (e.g. GCash) identifies one real transfer, so it
            // may appear once per method — across all locations, no exceptions
            // (D-109). NULLs (cash) are not compared by UNIQUE.
            $table->unique(['payment_method_id', 'reference_number'], 'payments_method_reference_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_order_type_check CHECK (order_type IN ('dine_in','takeout','advance_order'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','confirmed','preparing','ready','completed','cancelled','refunded'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','pending_verification','paid','partially_refunded','refunded'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal >= 0 AND discount_total >= 0 AND service_charge >= 0 AND grand_total >= 0 AND amount_paid >= 0 AND amount_due >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND line_total >= 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','verified','failed','refunded','partially_refunded'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amounts_check CHECK (amount > 0 AND change_amount >= 0 AND (amount_tendered IS NULL OR amount_tendered >= amount))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
