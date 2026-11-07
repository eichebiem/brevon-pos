<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Reference data only (SRS Appendix B, D-054): units of measure and the
 * shop's seven categories, all top level, in menu order (D-177). No products
 * or prices here; MenuSeeder adds the menu.
 */
class CatalogReferenceSeeder extends Seeder
{
    /** The categories, in the order the POS tabs show them (D-177). */
    public const CATEGORIES = ['Regular', 'Premium', 'Assorted boxes', 'Coffee', 'Matcha series', 'Lemonade series', 'Combos'];

    public function run(): void
    {
        foreach (
            [
                ['pc', 'Piece', 0],
                ['box', 'Box', 0],
                ['g', 'Gram', 3],
                ['kg', 'Kilogram', 3],
                ['ml', 'Millilitre', 3],
                ['l', 'Litre', 3],
            ] as [$code, $name, $precision]
        ) {
            UnitOfMeasure::query()->firstOrCreate(['code' => $code], ['name' => $name, 'precision' => $precision]);
        }

        foreach (self::CATEGORIES as $order => $name) {
            Category::query()->firstOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name, 'sort_order' => ($order + 1), 'is_active' => true],
            );
        }
    }
}
