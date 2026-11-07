<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Locations\LocationService;
use App\Domain\Locations\TerminalService;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development pop-up "SM Rosales" with its terminal "SM Rosales POS"
 * (D-178). Runs ONLY when APP_ENV=local.
 *
 * Created through LocationService and TerminalService, so it gets the next
 * pop-up code (POPnn) and terminal code (POS-n) and is audited like one
 * made on the Locations screen. When it is first created it sells the same
 * doughnuts as the main branch ("Copy main branch doughnuts"), so run it
 * after MenuSeeder. Idempotent: an existing SM Rosales and its terminal are
 * left as they are, so changes made in the app are kept.
 */
class LocalLocationsSeeder extends Seeder
{
    public const NAME = 'SM Rosales';

    public const TERMINAL = 'SM Rosales POS';

    public function __construct(
        private readonly LocationService $locations,
        private readonly TerminalService $terminals,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalLocationsSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        // Audited as the default Owner Harris (D-102), created if missing.
        if (! User::role(PermissionCatalog::OWNER)->exists()) {
            $this->call(DefaultOwnersSeeder::class);
        }
        $actor = User::query()->where('username', DefaultOwnersSeeder::ACCOUNTS[0]['username'])->first()
            ?? User::role(PermissionCatalog::OWNER)->orderBy('id')->firstOrFail();

        $location = Branch::query()->where('location_type', LocationType::PopUp)->where('name', self::NAME)->first();
        if ($location === null) {
            $location = $this->locations->createPopUp($actor, ['name' => self::NAME], copyAvailabilityFromMain: true);
            $this->command?->info("Pop-up ready: {$location->name} ({$location->code}).");
        }

        if (! Terminal::query()->where('branch_id', $location->id)->where('name', self::TERMINAL)->exists()) {
            $terminal = $this->terminals->create($actor, $location, self::TERMINAL);
            $this->command?->info("Terminal ready: {$terminal->name} ({$terminal->terminal_code}).");
        }
    }
}
