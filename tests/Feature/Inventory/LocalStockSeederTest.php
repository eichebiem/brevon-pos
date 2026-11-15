<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** D-190: local development opening stock of 3–10 of every doughnut. */
class LocalStockSeederTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private function seedAll(string $env): void
    {
        $this->app['env'] = $env;
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
    }

    public function test_local_gets_3_to_10_of_every_doughnut_at_each_location(): void
    {
        $this->seedAll('local');

        $doughnuts = Product::query()->where('inventory_tracking_mode', 'finished_stock')->pluck('inventory_item_id');
        $this->assertCount(14, $doughnuts);
        $locations = DB::table('product_branch')->where('is_available', true)->distinct()->pluck('branch_id');
        $this->assertCount(2, $locations, 'main branch and SM Rosales');

        foreach ($locations as $locationId) {
            $rows = BranchInventory::query()->where('branch_id', $locationId)->get();
            $this->assertEqualsCanonicalizing($doughnuts->all(), $rows->pluck('inventory_item_id')->all());
            foreach ($rows as $row) {
                $this->assertGreaterThanOrEqual(LocalStockSeeder::MIN, (float) $row->quantity_on_hand);
                $this->assertLessThanOrEqual(LocalStockSeeder::MAX, (float) $row->quantity_on_hand);
                $this->assertSame((float) $row->quantity_on_hand, floor((float) $row->quantity_on_hand), 'whole pieces');
            }
            $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.opening_stock', 'branch_id' => $locationId]);
        }
        $this->assertSame(28, InventoryMovement::query()->where('movement_type', InventoryMovementType::Opening->value)->count());

        // Re-seeding keeps the stock as it is.
        $before = BranchInventory::query()->orderBy('id')->pluck('quantity_on_hand')->all();
        $this->seedAll('local');
        $this->assertSame($before, BranchInventory::query()->orderBy('id')->pluck('quantity_on_hand')->all());
        $this->assertSame(28, InventoryMovement::query()->count());
    }

    public function test_other_environments_get_no_stock(): void
    {
        foreach (['testing', 'staging', 'production'] as $env) {
            $this->seedAll($env);
        }

        $this->assertSame(0, InventoryMovement::query()->count());
    }
}
