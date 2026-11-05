<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use Database\Seeders\Concerns\SeedsAccounts;
use Illuminate\Database\Seeder;

/**
 * The shop's three Owner accounts, requested by the user for every
 * environment including production (D-102).
 *
 * SECURITY: the starting password is the known value "password". Each owner
 * must change it right after the first sign-in on any shared or live server
 * (go-live checklist). Re-seeding never resets a changed password.
 */
class DefaultOwnersSeeder extends Seeder
{
    use SeedsAccounts;

    public const PASSWORD = 'password';

    /** @var list<array{username: string, name: string, email: string, role: string}> */
    public const ACCOUNTS = [
        ['username' => 'harris', 'name' => 'Harris', 'email' => 'harris@brevon.test', 'role' => PermissionCatalog::OWNER],
        ['username' => 'chris', 'name' => 'Chris', 'email' => 'chris@brevon.test', 'role' => PermissionCatalog::OWNER],
        ['username' => 'desiree', 'name' => 'Desiree', 'email' => 'desiree@brevon.test', 'role' => PermissionCatalog::OWNER],
    ];

    public function run(): void
    {
        $this->seedAccounts(self::ACCOUNTS, self::PASSWORD);
    }
}
