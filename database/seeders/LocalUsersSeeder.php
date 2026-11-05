<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use Database\Seeders\Concerns\SeedsAccounts;
use Illuminate\Database\Seeder;

/**
 * Local development account "Cashier 1" (D-100). Known password — runs ONLY
 * when APP_ENV=local; never in testing, staging or production. The owners
 * come from DefaultOwnersSeeder (D-102).
 */
class LocalUsersSeeder extends Seeder
{
    use SeedsAccounts;

    public const PASSWORD = 'password';

    /** @var list<array{username: string, name: string, email: string, role: string}> */
    public const ACCOUNTS = [
        ['username' => 'cashier1', 'name' => 'Cashier 1', 'email' => 'cashier1@brevon.test', 'role' => PermissionCatalog::CASHIER],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalUsersSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        $this->seedAccounts(self::ACCOUNTS, self::PASSWORD);
    }
}
