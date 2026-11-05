<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Option groups / add-ons (FR-018). Options are deactivated, never deleted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('selection_type', 20)->default('single');
            $table->unsignedInteger('min_selections')->default(0);
            $table->unsignedInteger('max_selections')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE option_groups ADD CONSTRAINT option_groups_type_check CHECK (selection_type IN ('single', 'multiple'))");
        DB::statement('ALTER TABLE option_groups ADD CONSTRAINT option_groups_range_check CHECK (max_selections IS NULL OR max_selections >= min_selections)');
        DB::statement("ALTER TABLE option_groups ADD CONSTRAINT option_groups_single_check CHECK (selection_type <> 'single' OR (max_selections = 1 AND min_selections <= 1))");

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['option_group_id', 'name']);
        });

        DB::statement('ALTER TABLE product_options ADD CONSTRAINT product_options_price_check CHECK (price_delta >= 0)');

        Schema::create('product_option_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'option_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_group');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('option_groups');
    }
};
