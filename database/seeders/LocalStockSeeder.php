<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\OpeningStock;
use App\Domain\Shared\BusinessClock;
use App\Enums\InventoryItemType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development stock (D-190): opening stock of a random 3–10 pieces
 * for every doughnut at every open location that sells it (the main branch
 * and, locally, SM Rosales). Runs ONLY when APP_ENV=local.
 *
 * Posted through OpeningStock, so it is a normal "Opening stock" movement,
 * audited as the default Owner Harris, with no unit cost. Opening stock is
 * only allowed once per item and location, so re-running it skips doughnuts
 * that already have stock history there and never changes counts made in
 * the app. Run it after MenuSeeder and LocalLocationsSeeder.
 */
class LocalStockSeeder extends Seeder
{
    public const MIN = 3;

    public const MAX = 10;

    public function __construct(private readonly OpeningStock $opening) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalStockSeeder skipped: only runs when APP_ENV=local.');

            return;
        }

        if (! User::role(PermissionCatalog::OWNER)->exists()) {
            $this->call(DefaultOwnersSeeder::class);
        }
        $actor = User::query()->where('username', DefaultOwnersSeeder::ACCOUNTS[0]['username'])->first()
            ?? User::role(PermissionCatalog::OWNER)->orderBy('id')->firstOrFail();

        $today = BusinessClock::today();
        foreach (Branch::query()->orderBy('id')->get() as $location) {
            if (! $location->acceptsNewOperationsOn($today)) {
                continue;
            }

            /** @var list<array{inventory_item_id: int, quantity: string|null, unit_cost: string|null}> $lines */
            $lines = $this->opening->eligibleItems($location)
                ->filter(fn (InventoryItem $item) => $item->item_type === InventoryItemType::FinishedGood
                    && $item->product()->where('is_active', true)
                        ->whereHas('locations', fn ($q) => $q->whereKey($location->id)->where('product_branch.is_available', true))
                        ->exists())
                ->map(fn (InventoryItem $item) => [
                    'inventory_item_id' => $item->id,
                    'quantity' => (string) random_int(self::MIN, self::MAX),
                    'unit_cost' => null,
                ])
                ->values()
                ->all();

            if ($lines === []) {
                continue;
            }

            $count = $this->opening->post($actor, $location, $lines);
            $this->command?->info("Opening stock: {$count} doughnuts at {$location->name} ({$location->code}).");
        }
    }
}
