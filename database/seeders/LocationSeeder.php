<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Terminal;
use Illuminate\Database\Seeder;

/**
 * Exactly one permanent location (FR-103, D-054). Name and address are
 * provisional until the owner confirms them. No pop-ups are seeded.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::query()->firstOrCreate(
            ['code' => 'BRV01'],
            [
                'name' => 'Brevon Doughnuts & Coffee — Main',
                'location_type' => LocationType::Permanent,
                'timezone' => 'Asia/Manila',
                'currency' => 'PHP',
                'is_active' => true,
            ],
        );

        Terminal::query()->firstOrCreate(
            ['branch_id' => $main->id, 'terminal_code' => 'POS-1'],
            ['name' => 'Main branch', 'is_active' => true],
        );
    }
}
