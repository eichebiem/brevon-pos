<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Phase 1 seeds nothing. No user accounts are ever seeded with a known
     * password (prompt §5, D-022): the local owner account is created in
     * Phase 2 by `php artisan brevon:create-owner`. Roles, permissions, the
     * single permanent location, payment methods and categories are added
     * by the phases that introduce those tables (D-054).
     */
    public function run(): void
    {
        //
    }
}
