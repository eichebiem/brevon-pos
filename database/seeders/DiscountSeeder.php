<?php

namespace Database\Seeders;

use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use Illuminate\Database\Seeder;

/**
 * Idempotent. The Senior Citizen / PWD discount the owner set at 12% with
 * VAT disregarded (D-068). It starts switched ON (user request 2026-11-07,
 * D-175); the owner can turn it off under Discounts. An existing row is left
 * as it is, so re-seeding never undoes the owner's choice.
 */
class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        if (Discount::query()->where('kind', DiscountKind::SeniorPwd)->exists()) {
            return;
        }

        (new Discount)->forceFill([
            'code' => 'SCPWD',
            'name' => 'Senior Citizen / PWD',
            'kind' => DiscountKind::SeniorPwd,
            'discount_type' => DiscountType::Percentage,
            'value' => '12.00',
            'scope' => DiscountScope::Order,
            'requires_approval' => false,
            'is_active' => true,
        ])->save();
    }
}
