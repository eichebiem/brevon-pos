<?php

namespace Database\Seeders\Concerns;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\User;

/**
 * Creates missing accounts at the permanent location. An existing account
 * (matched by username) keeps its password and details; only its role and
 * location assignment are ensured.
 */
trait SeedsAccounts
{
    /**
     * @param  list<array{username: string, name: string, email: string, role: string}>  $accounts
     */
    private function seedAccounts(array $accounts, string $password): void
    {
        $main = Branch::query()->where('location_type', LocationType::Permanent)->firstOrFail();

        foreach ($accounts as $account) {
            $user = User::query()->firstOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $password,
                    'is_active' => true,
                ],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $user->syncRoles([$account['role']]);
            $user->branches()->syncWithoutDetaching([$main->id => ['is_default' => true]]);

            $this->command?->info("{$account['role']} ready: username \"{$account['username']}\".");
        }
    }
}
