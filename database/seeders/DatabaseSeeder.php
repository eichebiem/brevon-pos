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
     * shop's menu (D-101) and, locally only, the SM Rosales pop-up with its
     * terminal and "Cashier 1" / "Cashier 2" (D-100, D-178).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            CatalogReferenceSeeder::class,
            PaymentMethodSeeder::class,
            DiscountSeeder::class,
            DefaultOwnersSeeder::class,
            // The shop's menu, in every environment (D-101).
            MenuSeeder::class,
            // Local only (D-100, D-178): SM Rosales after the menu, so it gets
            // the main branch's doughnuts; then the cashiers.
            LocalLocationsSeeder::class,
            LocalUsersSeeder::class,
        ]);
    }
}
