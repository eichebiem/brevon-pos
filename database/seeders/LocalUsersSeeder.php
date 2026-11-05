<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development accounts requested by the user (D-084, D-100):
 * Owner "Harris" and Cashier "Cashier 1". Known password — runs ONLY when
 * APP_ENV=local; never in testing, staging or production.
 *
 * Existing accounts are left as they are (a changed password is kept);
 * only missing accounts are created.
 */
class LocalUsersSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var list<array{username: string, name: string, email: string, role: string}> */
    public const ACCOUNTS = [
        ['username' => 'harris', 'name' => 'Harris', 'email' => 'harris@brevon.test', 'role' => PermissionCatalog::OWNER],
        ['username' => 'cashier1', 'name' => 'Cashier 1', 'email' => 'cashier1@brevon.test', 'role' => PermissionCatalog::CASHIER],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalUsersSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        $main = Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();

        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->firstOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => self::PASSWORD,
                    'is_active' => true,
                ],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $user->syncRoles([$account['role']]);
            $user->branches()->syncWithoutDetaching([$main->id => ['is_default' => true]]);

            $this->command?->info("Local {$account['role']} ready: username \"{$account['username']}\" (local only).");
        }
    }
}
