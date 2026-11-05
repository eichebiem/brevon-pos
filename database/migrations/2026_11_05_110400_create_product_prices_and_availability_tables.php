<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price history (FR-019, BR-015) and per-location availability (FR-021, D-064).
 * A price row is never edited except to close it (effective_until); the
 * generated guards allow only one open row and one row per start time for
 * each (product, variant, location) scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('scope_key', 64)->storedAs("CONCAT(`product_id`, '-', IFNULL(`variant_id`, 0), '-', IFNULL(`branch_id`, 0))");
            $table->tinyInteger('open_guard')->nullable()->storedAs('IF(`effective_until` IS NULL, 1, NULL)');
            $table->timestamps();

            $table->index(['product_id', 'variant_id', 'branch_id', 'effective_from'], 'product_prices_lookup_index');
            $table->unique(['scope_key', 'open_guard']);
            $table->unique(['scope_key', 'effective_from']);
        });

        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_price_check CHECK (price >= 0 AND (cost_price IS NULL OR cost_price >= 0))');
        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');

        Schema::create('product_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_branch');
        Schema::dropIfExists('product_prices');
    }
};
