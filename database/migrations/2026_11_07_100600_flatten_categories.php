<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seven top-level categories (user request 2026-11-07, D-177): Regular,
 * Premium, Assorted boxes, Coffee, Matcha series, Lemonade series, Combos.
 *
 * On a database seeded before this change the six sub-categories move up to
 * the top level and every one of the seven gets its menu position. The old
 * groups Doughnuts, Cold Drinks and Other are then deleted. The migration
 * refuses to run if one of them still holds products, sub-categories or a
 * category discount: move those first on the Categories screen. Categories
 * the owner added themselves are left as they are. A fresh database has no
 * categories yet, so nothing happens and CatalogReferenceSeeder creates them.
 */
return new class extends Migration
{
    private const SEVEN = ['Regular', 'Premium', 'Assorted boxes', 'Coffee', 'Matcha series', 'Lemonade series', 'Combos'];

    private const OLD_GROUPS = ['Doughnuts', 'Cold Drinks', 'Other'];

    public function up(): void
    {
        DB::transaction(function () {
            $groups = DB::table('categories')->whereNull('parent_id')->whereIn('name', self::OLD_GROUPS)->pluck('id', 'name');

            foreach (self::SEVEN as $i => $name) {
                // The seeded one: top level, or under one of the old groups.
                $id = DB::table('categories')->where('name', $name)
                    ->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn('parent_id', $groups->values()))
                    ->orderByRaw('parent_id IS NOT NULL')->value('id');
                if ($id !== null) {
                    DB::table('categories')->where('id', $id)->update(['parent_id' => null, 'sort_order' => $i + 1, 'updated_at' => now()]);
                }
            }

            foreach ($groups as $name => $id) {
                $inUse = DB::table('products')->where('category_id', $id)->exists()
                    || DB::table('categories')->where('parent_id', $id)->exists()
                    || DB::table('discounts')->where('category_id', $id)->exists();
                if ($inUse) {
                    throw new RuntimeException("The {$name} category still holds products, sub-categories or a discount; move them before removing it (D-177).");
                }
                DB::table('categories')->where('id', $id)->delete();
            }
        });
    }

    public function down(): void
    {
        // The old groups are not recreated; categories can be rearranged on the Categories screen.
    }
};
