<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts (FR-073, FR-074; D-027, D-060, D-094). A shift belongs to a
 * terminal and its location is copied from the terminal. The generated
 * open_guard (1 while open, NULL when closed) lets UNIQUE indexes allow at
 * most one open shift per cashier and per terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('shift_number', 50);
            $table->date('business_date');
            $table->dateTime('opened_at');
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->string('status', 20)->default('open');
            $table->text('closing_notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            // Manager review of the closed shift (D-126–D-130).
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->tinyInteger('open_guard')->nullable()->storedAs("IF(`status` = 'open', 1, NULL)");
            $table->timestamps();

            $table->unique(['branch_id', 'shift_number']);
            $table->unique(['user_id', 'open_guard'], 'cashier_shifts_user_open_unique');
            $table->unique(['terminal_id', 'open_guard'], 'cashier_shifts_terminal_open_unique');
            $table->index(['branch_id', 'user_id', 'status']);
            $table->index(['branch_id', 'business_date']);
        });

        DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_cash_check CHECK (opening_cash >= 0 AND (counted_cash IS NULL OR counted_cash >= 0) AND (expected_cash IS NULL OR expected_cash >= 0))');
        DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_closed_check CHECK (status = 'open' OR (closed_at IS NOT NULL AND counted_cash IS NOT NULL AND expected_cash IS NOT NULL AND variance IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
