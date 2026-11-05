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
     * Seeds reference data, plus a known-password Owner ("harris") that is
     * created ONLY when APP_ENV=local (D-084). Other environments get no
     * accounts; use `php artisan brevon:create-owner` or the Users screen.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            CatalogReferenceSeeder::class,
            PaymentMethodSeeder::class,
            LocalUsersSeeder::class,
        ]);
    }
}
