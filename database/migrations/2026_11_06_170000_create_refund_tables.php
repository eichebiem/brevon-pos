<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds and voids (SRS §8.4; FR-041–FR-045, BR-005, UC-005; D-150–D-155).
 *
 * Additions to §8.4: `refunds.kind` (refund of a completed sale, or the
 * void record a cancellation writes), `refunds.cashier_shift_id` (the drawer
 * cash was handed back from), `refunds.reference_number` (the outgoing
 * e-wallet transfer). Refund rows are written once and never edited or
 * deleted (model guards); original orders and payments stay as they were,
 * apart from their status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('refund_number', 50)->unique();
            $table->string('kind', 20)->default('refund');
            $table->decimal('amount', 12, 2);
            $table->string('refund_method', 30);
            $table->string('reference_number', 120)->nullable();
            $table->text('reason');
            $table->string('status', 30)->default('processed');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['branch_id', 'processed_at']);
            $table->index('cashier_shift_id');
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('amount', 12, 2);
            $table->boolean('return_to_stock')->default(false);
            $table->timestamps();

            $table->unique(['refund_id', 'order_item_id']);
            $table->index('order_item_id');
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_check CHECK (amount > 0 AND kind IN ('refund','void') AND status IN ('pending','processed','rejected'))");
        DB::statement('ALTER TABLE refund_items ADD CONSTRAINT refund_items_check CHECK (quantity > 0 AND amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
