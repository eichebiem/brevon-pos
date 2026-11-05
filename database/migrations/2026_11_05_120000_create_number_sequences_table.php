<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location, per-business-day counters for document numbers (D-035).
 * Rows are incremented under SELECT … FOR UPDATE inside the caller's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('sequence_type', 40);
            $table->date('business_date');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'sequence_type', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
