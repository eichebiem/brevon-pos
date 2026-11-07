<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Domain\Shifts\ShiftService;
use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderDiscountBeneficiary;
use App\Models\OrderItem;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DiscountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 9a: discounts and SC/PWD (FR-083–FR-085, FR-044; T-DSC-1, T-SCD-1/2, T-PRIV-1; D-145–D-149). */
class DiscountTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->manager = $this->staff(PermissionCatalog::MANAGER, null, ['username' => 'mgr', 'password' => 'secret-pass']);
        $this->cashier = $this->cashierOnShift();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function discount(array $attributes = []): Discount
    {
        $discount = new Discount;
        $discount->forceFill([
            'name' => 'Promo',
            'kind' => DiscountKind::Standard,
            'discount_type' => DiscountType::Percentage,
            'value' => '10.00',
            'scope' => DiscountScope::Order,
            'is_active' => true,
            ...$attributes,
        ])->save();

        return $discount;
    }

    private function seniorPwd(): Discount
    {
        $this->seed(DiscountSeeder::class);

        return Discount::query()->where('kind', DiscountKind::SeniorPwd)->sole();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $discount
     */
    private function sell(array $items, array $discount, ?User $as = null, array $extra = []): TestResponse
    {
        return $this->checkout($as ?? $this->cashier, $this->payload($items, extra: ['discount' => $discount, ...$extra]));
    }

    /** @return array<string, string> */
    private function person(): array
    {
        return ['type' => 'senior_citizen', 'name' => 'Juan Dela Cruz', 'card_number' => 'SC-123456', 'address' => '12 Rizal St, Imus, Cavite'];
    }

    public function test_percentage_and_fixed_discounts_are_worked_out_on_the_server_and_spread_over_the_lines(): void
    {
        $choco = $this->doughnut('44.00', '10');
        $glazed = $this->doughnut('35.00', '10', name: 'Glazed');
        $promo = $this->discount(['value' => '12.50']);

        // (3 × 44 + 35) = 167 × 12.5% = 20.875 → 20.88
        $this->sell([['product_id' => $choco->id, 'quantity' => 3], ['product_id' => $glazed->id, 'quantity' => 1]], ['id' => $promo->id], extra: ['expected_total' => '146.12'])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $this->assertSame(['167.00', '20.88', '146.12', '146.12'], [$order->subtotal, $order->discount_total, $order->grand_total, $order->payments()->sole()->amount]);
        $parts = OrderItem::query()->orderBy('id')->pluck('discount_amount')->all();
        $this->assertSame(['16.50', '4.38'], $parts);

        $row = OrderDiscount::query()->sole();
        $this->assertSame(['Promo', 'percentage', '12.50', '20.88', $this->cashier->id, null], [
            $row->discount_name_snapshot, $row->discount_type_snapshot->value, $row->discount_value_snapshot, $row->discount_amount, $row->applied_by, $row->approved_by,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'discount.applied', 'auditable_id' => $row->id]);

        // A fixed discount is never more than what it covers.
        $fifty = $this->discount(['name' => 'Fifty off', 'discount_type' => DiscountType::Fixed, 'value' => '50.00']);
        $this->sell([['product_id' => $glazed->id, 'quantity' => 1]], ['id' => $fifty->id])->assertSessionHasErrors('items');
        $this->sell([['product_id' => $glazed->id, 'quantity' => 2]], ['id' => $fifty->id])->assertSessionHasNoErrors();
        $this->assertSame('20.00', Order::query()->latest('id')->firstOrFail()->grand_total);

        // Editing the preset later never changes past sales.
        $promo->forceFill(['name' => 'Renamed', 'value' => '50.00'])->save();
        $this->assertSame(['Promo', '12.50'], [$row->refresh()->discount_name_snapshot, $row->discount_value_snapshot]);
    }

    public function test_product_and_category_discounts_cover_only_their_lines(): void
    {
        $choco = $this->doughnut('44.00', '10');
        $glazed = $this->doughnut('35.00', '10', name: 'Glazed');
        $perGlazed = $this->discount(['scope' => DiscountScope::Product, 'product_id' => $glazed->id, 'value' => '20.00']);

        $this->sell([['product_id' => $choco->id, 'quantity' => 1], ['product_id' => $glazed->id, 'quantity' => 2]], ['id' => $perGlazed->id])->assertSessionHasNoErrors();
        $this->assertSame(['0.00', '14.00'], OrderItem::query()->orderBy('id')->pluck('discount_amount')->all());

        $this->sell([['product_id' => $choco->id, 'quantity' => 1]], ['id' => $perGlazed->id])->assertSessionHasErrors('discount');

        $category = $this->discount(['scope' => DiscountScope::Category, 'category_id' => $choco->category_id, 'discount_type' => DiscountType::Fixed, 'value' => '5.00']);
        $this->sell([['product_id' => $choco->id, 'quantity' => 1], ['product_id' => $glazed->id, 'quantity' => 1]], ['id' => $category->id])->assertSessionHasNoErrors();
        $this->assertSame('5.00', Order::query()->latest('id')->firstOrFail()->discount_total);
    }

    public function test_switched_off_out_of_date_and_used_up_discounts_are_refused(): void
    {
        $doughnut = $this->doughnut('44.00', '20');
        $line = [['product_id' => $doughnut->id, 'quantity' => 1]];

        $this->sell($line, ['id' => $this->discount(['is_active' => false])->id])->assertSessionHasErrors('discount');
        $this->sell($line, ['id' => $this->discount(['starts_at' => now()->addDay()])->id])->assertSessionHasErrors('discount');
        $this->sell($line, ['id' => $this->discount(['ends_at' => now()->subMinute()])->id])->assertSessionHasErrors('discount');
        $this->sell($line, ['id' => 999999])->assertSessionHasErrors('discount');

        ['product' => $latte, 'hot' => $hot] = $this->coffee();
        $drink = [['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 1]];
        $once = $this->discount(['max_uses' => 1]);
        $this->sell($drink, ['id' => $once->id])->assertSessionHasNoErrors();
        $this->sell($drink, ['id' => $once->id])->assertSessionHasErrors('discount');

        // An order cancelled before D-169 gives its use back.
        $order = Order::query()->sole();
        $this->pastCancellation($order, $this->manager, 'Wrong order');
        $this->sell($drink, ['id' => $once->id])->assertSessionHasNoErrors();
        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_discount_over_the_limit_or_marked_for_approval_needs_a_manager(): void
    {
        $doughnut = $this->doughnut('100.00', '20');
        $line = [['product_id' => $doughnut->id, 'quantity' => 3]];
        $half = $this->discount(['value' => '50.00']); // 150.00 off, over the ₱100.00 default
        $small = $this->discount(['name' => 'Small', 'value' => '10.00', 'requires_approval' => true]);

        $quote = $this->actingAs($this->cashier)->postJson('/pos/quote', ['items' => $line, 'discount' => ['id' => $half->id]])->assertOk();
        $this->assertSame(['150.00', true], [$quote->json('discount.amount'), $quote->json('discount_needs_approval')]);

        $this->sell($line, ['id' => $half->id])->assertSessionHasErrors('discount.approver');
        $this->sell($line, ['id' => $small->id])->assertSessionHasErrors('discount.approver');
        $this->sell($line, ['id' => $half->id, 'approver' => ['username' => 'mgr', 'password' => 'wrong']])->assertSessionHasErrors('discount.approver');
        $this->sell($line, ['id' => $half->id, 'approver' => ['username' => $this->cashier->username, 'password' => 'password']])->assertSessionHasErrors('discount.approver');
        $this->assertSame(0, Order::query()->count());

        $this->sell($line, ['id' => $half->id, 'approver' => ['username' => 'mgr', 'password' => 'secret-pass']])->assertSessionHasNoErrors();
        $row = OrderDiscount::query()->sole();
        $this->assertSame([$this->cashier->id, $this->manager->id, '150.00'], [$row->applied_by, $row->approved_by, $row->discount_amount]);

        // The limit is a store setting.
        app(StoreSettings::class)->update($this->owner, ['name' => 'Brevon', 'receipt_footer' => null, 'discount_approval_over' => '200.00']);
        $this->sell($line, ['id' => $half->id])->assertSessionHasNoErrors();
        $this->assertNull(OrderDiscount::query()->latest('id')->firstOrFail()->approved_by);
    }

    public function test_a_manager_from_another_location_cannot_approve_and_wrong_passwords_are_limited(): void
    {
        $doughnut = $this->doughnut('100.00', '20');
        $line = [['product_id' => $doughnut->id, 'quantity' => 3]];
        $half = $this->discount(['value' => '50.00']);
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $this->staff(PermissionCatalog::MANAGER, $popUp, ['username' => 'away', 'password' => 'secret-pass']);

        $this->sell($line, ['id' => $half->id, 'approver' => ['username' => 'away', 'password' => 'secret-pass']])->assertSessionHasErrors('discount.approver');

        RateLimiter::clear("discount-approval:{$this->cashier->id}");
        for ($i = 0; $i < 5; $i++) {
            $this->sell($line, ['id' => $half->id, 'approver' => ['username' => 'mgr', 'password' => "bad-{$i}"]]);
        }
        $this->sell($line, ['id' => $half->id, 'approver' => ['username' => 'mgr', 'password' => 'secret-pass']])->assertSessionHasErrors('discount.approver');
        $this->assertStringStartsWith('Too many tries.', (string) session('errors')?->first('discount.approver'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_manager_at_the_counter_approves_their_own_discount(): void
    {
        $doughnut = $this->doughnut('100.00', '20');
        $till = $this->staff(PermissionCatalog::MANAGER);
        $second = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Second till', 'is_active' => true]);
        app(ShiftService::class)->open($till, $second, Money::fromDecimal('500.00'));
        $half = $this->discount(['value' => '50.00']);

        $this->sell([['product_id' => $doughnut->id, 'quantity' => 3]], ['id' => $half->id], $till)->assertSessionHasNoErrors();
        $this->assertSame($till->id, OrderDiscount::query()->sole()->approved_by);
    }

    public function test_senior_citizen_discount_is_twelve_percent_of_the_beneficiarys_items_and_records_them(): void
    {
        $scpwd = $this->seniorPwd();
        $choco = $this->doughnut('44.00', '10');
        ['product' => $latte, 'hot' => $hot] = $this->coffee();
        $items = [['product_id' => $choco->id, 'quantity' => 2], ['product_id' => $latte->id, 'variant_id' => $hot->id, 'quantity' => 1]];

        // Details are required.
        $this->sell($items, ['id' => $scpwd->id])->assertSessionHasErrors(['discount.beneficiary.type', 'discount.beneficiary.name', 'discount.beneficiary.card_number', 'discount.beneficiary.address']);
        $this->sell($items, ['id' => $scpwd->id, 'beneficiary' => [...$this->person(), 'name' => 'Juan']])->assertSessionHasErrors('discount.beneficiary.name');
        $this->sell($items, ['id' => $scpwd->id, 'lines' => [5], 'beneficiary' => $this->person()])->assertSessionHasErrors('discount.lines');

        // Only the latte (₱120) is the senior's: 12% = 14.40; VAT is disregarded (D-068).
        $this->sell($items, ['id' => $scpwd->id, 'lines' => [1], 'beneficiary' => $this->person()])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $this->assertSame(['208.00', '14.40', '193.60'], [$order->subtotal, $order->discount_total, $order->grand_total]);
        $this->assertSame(['0.00', '14.40'], OrderItem::query()->orderBy('id')->pluck('discount_amount')->all());

        $person = OrderDiscountBeneficiary::query()->sole();
        $this->assertSame(['senior_citizen', 'Juan Dela Cruz', 'SC-123456', '12 Rizal St, Imus, Cavite'], [$person->beneficiary_type->value, $person->full_name, $person->card_number, $person->address]);
        // Encrypted at rest.
        $raw = DB::table('order_discount_beneficiaries')->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('Juan', (string) $raw->full_name);
        $this->assertStringNotContainsString('123456', (string) $raw->card_number);

        // The audit entry is masked.
        $audit = AuditLog::query()->where('event', 'discount.applied')->sole();
        $this->assertStringNotContainsString('Juan', (string) json_encode($audit->new_values));
        $this->assertStringContainsString('J*** D*** C***', (string) json_encode($audit->new_values));

        $this->expectException(LogicException::class);
        $person->forceFill(['full_name' => 'Someone Else'])->save();
    }

    public function test_beneficiary_details_are_masked_unless_the_viewer_may_see_them(): void
    {
        $scpwd = $this->seniorPwd();
        $choco = $this->doughnut('44.00', '10');
        $this->sell([['product_id' => $choco->id, 'quantity' => 1]], ['id' => $scpwd->id, 'beneficiary' => $this->person()])->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($this->manager)->get("/pos/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('receipt.discount.beneficiary.name', 'J*** D*** C***')
                ->where('receipt.discount.beneficiary.card_number', '••••3456')
                ->where('receipt.discount.beneficiary.address', null)
                ->where('workflow.can_view_beneficiary', false));
        $this->actingAs($this->manager)->get("/pos/orders/{$order->id}/receipt")->assertOk()
            ->assertDontSee('Juan Dela Cruz')->assertDontSee('Rizal');
        $this->actingAs($this->manager)->getJson("/pos/orders/{$order->id}/beneficiary")->assertForbidden();

        $this->actingAs($this->owner)->get("/pos/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('workflow.can_view_beneficiary', true));
        $this->actingAs($this->owner)->getJson("/pos/orders/{$order->id}/beneficiary")->assertOk()
            ->assertJson(['name' => 'Juan Dela Cruz', 'card_number' => 'SC-123456', 'address' => '12 Rizal St, Imus, Cavite', 'masked' => false]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'discount.beneficiary_viewed', 'auditable_id' => $order->id, 'user_id' => $this->owner->id]);
    }

    public function test_the_only_personal_data_columns_are_on_the_beneficiary_table(): void
    {
        $personal = ['full_name', 'card_number', 'address', 'customer_name', 'customer_phone', 'customer_email', 'phone', 'email', 'birthdate'];
        $allowed = [
            'order_discount_beneficiaries' => ['full_name', 'card_number', 'address'],
            // Staff accounts, suppliers and locations are business records, not customer data (D-067, D-135).
            'users' => ['email'], 'password_reset_tokens' => ['email'], 'employees' => ['phone', 'email'], 'suppliers' => ['phone', 'email', 'address'],
        ];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $found = array_values(array_intersect($personal, Schema::getColumnListing($table)));
            $this->assertSame([], array_values(array_diff($found, $allowed[$table] ?? [])), "Personal-data column on {$table}");
        }
    }

    public function test_no_tax_is_charged_and_the_total_is_subtotal_less_discount(): void
    {
        // Non-VAT business (D-165): the tax feature and its tables are gone.
        $this->assertFalse(Schema::hasTable('tax_rules'));
        $this->assertFalse(Schema::hasTable('order_tax_lines'));
        $this->assertFalse(Schema::hasColumn('orders', 'tax_total'));
        $this->assertFalse(Schema::hasColumn('order_items', 'tax_amount'));

        $doughnut = $this->doughnut('112.00', '20');
        $twelve = $this->discount(['discount_type' => DiscountType::Fixed, 'value' => '12.00']);
        $this->sell([['product_id' => $doughnut->id, 'quantity' => 1]], ['id' => $twelve->id])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $this->assertSame(['112.00', '12.00', '100.00', '100.00'], [$order->subtotal, $order->discount_total, $order->grand_total, $order->amount_paid]);
    }

    public function test_allocation_always_adds_up(): void
    {
        $parts = DiscountCalculator::allocate(Money::fromDecimal('10.00'), [0 => Money::fromDecimal('1.00'), 1 => Money::fromDecimal('1.00'), 2 => Money::fromDecimal('1.00')]);
        $this->assertSame(['3.34', '3.33', '3.33'], array_map(fn (Money $m) => $m->toDecimal(), array_values($parts)));
        $this->assertSame('0.00', DiscountCalculator::percentOf(Money::fromDecimal('0.04'), '12.00')->toDecimal());
        $this->assertSame('0.01', DiscountCalculator::percentOf(Money::fromDecimal('0.05'), '10.00')->toDecimal());
    }

    public function test_a_retry_with_the_same_discount_returns_the_same_order(): void
    {
        $doughnut = $this->doughnut('100.00', '20');
        $half = $this->discount(['value' => '50.00']);
        $payload = $this->payload([['product_id' => $doughnut->id, 'quantity' => 3]], extra: ['discount' => ['id' => $half->id, 'approver' => ['username' => 'mgr', 'password' => 'secret-pass']]]);

        $this->checkout($this->cashier, $payload)->assertSessionHasNoErrors();
        $this->checkout($this->cashier, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderDiscount::query()->count());
    }

    public function test_presets_are_managed_in_settings(): void
    {
        $this->actingAs($this->manager)->get('/admin/discounts')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/discounts')->assertOk();

        $this->actingAs($this->owner)->post('/admin/discounts', ['name' => '', 'discount_type' => 'percentage', 'value' => '120', 'scope' => 'product'])
            ->assertSessionHasErrors(['name', 'value', 'product_id']);
        $this->actingAs($this->owner)->post('/admin/discounts', ['name' => 'Staff meal', 'code' => 'staff20', 'discount_type' => 'percentage', 'value' => '20', 'scope' => 'order', 'starts_at' => '2026-11-10T09:00', 'ends_at' => '2026-11-09T09:00'])
            ->assertSessionHasErrors('ends_at');
        $this->actingAs($this->owner)->post('/admin/discounts', ['name' => 'Staff meal', 'code' => 'staff20', 'discount_type' => 'percentage', 'value' => '20', 'scope' => 'order', 'max_uses' => '50', 'requires_approval' => true, 'is_active' => true])
            ->assertSessionHasNoErrors();
        $staff = Discount::query()->where('code', 'STAFF20')->sole();
        $this->assertSame(['20.00', 50, true], [$staff->value, $staff->max_uses, $staff->requires_approval]);
        $this->actingAs($this->owner)->post('/admin/discounts', ['name' => 'Copy', 'code' => 'STAFF20', 'discount_type' => 'fixed', 'value' => '5', 'scope' => 'order'])->assertSessionHasErrors('code');

        // SC/PWD keeps its kind, scope and type; only rate, approval and on/off change.
        $scpwd = $this->seniorPwd();
        $this->actingAs($this->owner)->put("/admin/discounts/{$scpwd->id}", ['name' => 'Senior Citizen / PWD', 'discount_type' => 'fixed', 'scope' => 'product', 'value' => '20', 'is_active' => true])->assertSessionHasNoErrors();
        $scpwd->refresh();
        $this->assertSame([DiscountType::Percentage, DiscountScope::Order, '20.00', 'SCPWD'], [$scpwd->discount_type, $scpwd->scope, $scpwd->value, $scpwd->code]);

        $this->expectException(LogicException::class);
        $staff->delete();
    }

    public function test_the_tax_rules_screen_is_gone(): void
    {
        $this->actingAs($this->owner)->get('/admin/tax-rules')->assertNotFound();
        $this->actingAs($this->owner)->post('/admin/tax-rules', ['name' => 'VAT', 'rate' => '12'])->assertNotFound();
    }

    public function test_the_pos_offers_discounts_that_are_on_and_the_seeded_sc_pwd_starts_on(): void
    {
        $this->seed(DiscountSeeder::class);
        $this->seed(DiscountSeeder::class);
        $scpwd = Discount::query()->where('kind', DiscountKind::SeniorPwd)->sole();
        $this->assertSame(['12.00', true], [$scpwd->value, $scpwd->is_active], 'seeded once, switched on (D-175)');

        // Re-seeding keeps the owner's choice.
        $scpwd->forceFill(['is_active' => false])->save();
        $this->seed(DiscountSeeder::class);
        $this->assertFalse($scpwd->refresh()->is_active);
        $scpwd->forceFill(['is_active' => true])->save();

        $this->discount(['name' => 'Later', 'starts_at' => now()->addDay()]);
        $this->discount(['name' => 'Now']);
        $this->actingAs($this->cashier)->get('/pos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('discounts', 2)->where('canApproveDiscount', false));
    }
}
