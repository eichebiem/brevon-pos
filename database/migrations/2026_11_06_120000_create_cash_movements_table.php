<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drawer cash movements and shift review (SRS §8.6; FR-076–FR-082;
 * D-126–D-130). Movements are append-only: a mistake is fixed with a new
 * correction row, never by editing or deleting one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 30);
            // Positive for every type except corrections, which carry their sign (D-129).
            $table->decimal('amount', 12, 2);
            // For corrections only: which closing figure is corrected.
            $table->string('corrects', 20)->nullable();
            $table->text('reason');
            $table->string('status', 20);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['cashier_shift_id', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (movement_type IN ('cash_in','cash_out','cash_paid_out','cash_refund','correction'))");
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_status_check CHECK (status IN ('pending','approved','rejected'))");
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK ((movement_type = 'correction' AND amount <> 0 AND corrects IN ('expected','counted')) OR (movement_type <> 'correction' AND amount > 0 AND corrects IS NULL))");

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dateTime('reviewed_at')->nullable()->after('approved_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropColumn(['reviewed_at', 'review_notes']);
        });
        Schema::dropIfExists('cash_movements');
    }
};
