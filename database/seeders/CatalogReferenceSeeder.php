<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Reference data only (SRS Appendix B, D-054): units of measure and the four
 * starting categories. No products or prices — those are entered by the
 * owner (Appendix B: "imported only after owner approval").
 */
class CatalogReferenceSeeder extends Seeder
{
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

        foreach (['Doughnuts', 'Coffee', 'Cold Drinks', 'Other'] as $order => $name) {
            Category::query()->firstOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name, 'sort_order' => ($order + 1), 'is_active' => true],
            );
        }
    }
}
