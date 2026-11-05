<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Store settings (FR-013, FR-101). NULL branch = global. Never holds secrets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->string('value_type', 30)->default('string');
            $table->boolean('is_sensitive')->default(false);
            // 0 for global rows so UNIQUE also applies when branch_id is NULL.
            $table->unsignedBigInteger('branch_scope')->storedAs('IFNULL(`branch_id`, 0)');
            $table->timestamps();

            $table->unique(['branch_scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
