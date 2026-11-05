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
     * Seeds reference data, the three default Owners (every environment,
     * known starting password — change it after first sign-in, D-102), the
     * local-only "Cashier 1" (D-100) and the shop's menu (D-101).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            CatalogReferenceSeeder::class,
            PaymentMethodSeeder::class,
            DefaultOwnersSeeder::class,
            LocalUsersSeeder::class,
            // The shop's menu, in every environment (D-101).
            MenuSeeder::class,
        ]);
    }
}
