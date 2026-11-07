<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operating locations: the single permanent branch and temporary pop-up
 * stands (SRS FR-011, FR-103; D-057, D-058, D-062). Never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('location_type', 20);
            $table->string('address_line')->nullable();
            $table->string('barangay', 100)->nullable();
            $table->string('city_municipality', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->char('currency', 3)->default('PHP');
            $table->boolean('is_active')->default(true);
            $table->date('operating_starts_on')->nullable();
            $table->date('operating_ends_on')->nullable();
            // 1 for the permanent location, NULL otherwise: UNIQUE allows only one permanent row (D-058).
            $table->tinyInteger('permanent_guard')->nullable()
                ->storedAs("IF(`location_type` = 'permanent', 1, NULL)");
            $table->dateTime('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('deactivation_reason')->nullable();
            $table->timestamps();

            $table->unique('permanent_guard');
            $table->index(['location_type', 'is_active']);
        });

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_location_type_check CHECK (location_type IN ('permanent', 'pop_up'))");
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_operating_period_check CHECK (operating_ends_on IS NULL OR operating_starts_on IS NULL OR operating_ends_on >= operating_starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
