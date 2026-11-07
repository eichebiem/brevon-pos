<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Models\Branch;
use Database\Seeders\Concerns\SeedsAccounts;
use Illuminate\Database\Seeder;

/**
 * Local development accounts (D-100, D-178): "Cashier 1" at the main branch
 * and "Cashier 2" at the SM Rosales pop-up (from LocalLocationsSeeder, run
 * first if missing). Known password — runs ONLY when APP_ENV=local; never in
 * testing, staging or production. The owners come from DefaultOwnersSeeder
 * (D-102).
 */
class LocalUsersSeeder extends Seeder
{
    use SeedsAccounts;

    public const PASSWORD = 'password';

    /** @var list<array{username: string, name: string, email: string, role: string, location?: string}> */
    public const ACCOUNTS = [
        ['username' => 'cashier1', 'name' => 'Cashier 1', 'email' => 'cashier1@brevon.test', 'role' => PermissionCatalog::CASHIER],
        ['username' => 'cashier2', 'name' => 'Cashier 2', 'email' => 'cashier2@brevon.test', 'role' => PermissionCatalog::CASHIER, 'location' => LocalLocationsSeeder::NAME],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalUsersSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        if (! Branch::query()->where('name', LocalLocationsSeeder::NAME)->exists()) {
            $this->call(LocalLocationsSeeder::class);
        }

        $this->seedAccounts(self::ACCOUNTS, self::PASSWORD);
    }
}
