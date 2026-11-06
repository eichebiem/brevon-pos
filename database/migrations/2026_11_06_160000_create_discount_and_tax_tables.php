<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts and tax (SRS §8.5; FR-083–FR-086; D-068, D-145–D-149).
 *
 * Schema additions to §8.5: `discounts.kind` (standard / senior_pwd),
 * `discounts.product_id` / `category_id` (what a product- or category-scoped
 * discount applies to), `order_discounts.discount_type_snapshot` /
 * `discount_value_snapshot`, `order_discount_beneficiaries` (D-068: the only
 * personal data in V1, encrypted, one row per SC/PWD order discount) and
 * `order_tax_lines.inclusive_snapshot`. Order rows are never edited or
 * deleted (model guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name', 100);
            $table->string('kind', 20)->default('standard');
            $table->string('discount_type', 20);
            $table->decimal('value', 12, 2);
            $table->string('scope', 20)->default('order');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            // One discount per order in V1 (D-146).
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('discount_name_snapshot', 100);
            $table->string('discount_code_snapshot', 50)->nullable();
            $table->string('discount_type_snapshot', 20);
            $table->decimal('discount_value_snapshot', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->text('reason')->nullable();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('discount_id');
        });

        Schema::create('order_discount_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_discount_id')->unique()->constrained()->restrictOnDelete();
            $table->string('beneficiary_type', 20);
            // Encrypted by the model (D-148); never indexed or searched.
            $table->text('full_name');
            $table->text('card_number');
            $table->text('address');
            $table->timestamps();
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->nullable();
            $table->decimal('rate', 7, 4);
            $table->string('calculation_method', 30)->default('percentage');
            $table->boolean('inclusive')->default(false);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('order_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_name_snapshot', 100);
            $table->decimal('tax_rate_snapshot', 7, 4);
            $table->boolean('inclusive_snapshot');
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->timestamps();

            $table->index('order_id');
        });

        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_check CHECK (
            kind IN ('standard','senior_pwd')
            AND discount_type IN ('percentage','fixed')
            AND scope IN ('order','product','category')
            AND value > 0 AND (discount_type <> 'percentage' OR value <= 100)
            AND (scope <> 'product' OR (product_id IS NOT NULL AND category_id IS NULL))
            AND (scope <> 'category' OR (category_id IS NOT NULL AND product_id IS NULL))
            AND (scope <> 'order' OR (product_id IS NULL AND category_id IS NULL))
            AND (kind <> 'senior_pwd' OR (scope = 'order' AND discount_type = 'percentage'))
            AND (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
            AND (max_uses IS NULL OR max_uses > 0))");
        DB::statement("ALTER TABLE order_discounts ADD CONSTRAINT order_discounts_check CHECK (discount_amount > 0 AND discount_value_snapshot > 0 AND discount_type_snapshot IN ('percentage','fixed'))");
        DB::statement("ALTER TABLE order_discount_beneficiaries ADD CONSTRAINT order_discount_beneficiaries_check CHECK (beneficiary_type IN ('senior_citizen','pwd'))");
        DB::statement("ALTER TABLE tax_rules ADD CONSTRAINT tax_rules_check CHECK (rate > 0 AND rate <= 100 AND calculation_method = 'percentage' AND (effective_until IS NULL OR effective_until >= effective_from))");
        DB::statement('ALTER TABLE order_tax_lines ADD CONSTRAINT order_tax_lines_check CHECK (tax_rate_snapshot > 0 AND taxable_amount >= 0 AND tax_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tax_lines');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('order_discount_beneficiaries');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('discounts');
    }
};
