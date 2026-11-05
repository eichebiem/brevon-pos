<?php

namespace Database\Seeders;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Cash and GCash (D-054). GCash is verified manually in V1 (BR-019, D-039).
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        PaymentMethod::query()->firstOrCreate(['code' => 'CASH'], [
            'name' => 'Cash', 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'is_active' => true,
        ]);
        PaymentMethod::query()->firstOrCreate(['code' => 'GCASH'], [
            'name' => 'GCash', 'type' => PaymentMethodType::EWallet, 'requires_reference' => true, 'is_active' => true,
        ]);
    }
}
