<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Products and variants (FR-016–FR-018, FR-020). Archived, never deleted (BR-014). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('product_type', 30);
            $table->string('inventory_tracking_mode', 30);
            // Finished-good stock item (D-025); one product per stock item.
            $table->foreignId('inventory_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('base_unit', 30)->default('piece');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available_for_pos')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (product_type IN ('finished_good', 'made_to_order', 'retail'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_tracking_check CHECK (inventory_tracking_mode IN ('finished_stock', 'recipe_at_sale', 'none'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_link_check CHECK (inventory_tracking_mode <> 'finished_stock' OR inventory_item_id IS NOT NULL)");

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('name', 100);
            $table->json('attributes')->nullable();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            // 1 for the default variant, NULL otherwise: one default per product.
            $table->tinyInteger('default_guard')->nullable()->storedAs('IF(`is_default`, 1, NULL)');
            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->unique(['product_id', 'default_guard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
