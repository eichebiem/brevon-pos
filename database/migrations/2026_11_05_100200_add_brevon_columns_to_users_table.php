<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SRS §8.2 users columns (FR-001, FR-005, FR-008; D-024). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 150)->change();
            $table->string('email', 150)->change();
            $table->string('username', 80)->nullable()->unique()->after('name');
            $table->foreignId('employee_id')->nullable()->unique()->after('password')
                ->constrained('employees')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('employee_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
