<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development Owner account requested by the user (D-084).
 * Known password — runs ONLY when APP_ENV=local; never in testing,
 * staging or production.
 */
class LocalOwnerSeeder extends Seeder
{
    public const USERNAME = 'harris';

    public const EMAIL = 'harris@brevon.test';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalOwnerSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        $main = Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();

        $user = User::query()->firstOrCreate(
            ['username' => self::USERNAME],
            [
                'name' => 'Harris',
                'email' => self::EMAIL,
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        $user->syncRoles([PermissionCatalog::OWNER]);
        $user->branches()->syncWithoutDetaching([$main->id => ['is_default' => true]]);

        $this->command?->info('Local Owner ready: username "harris" (local only).');
    }
}
