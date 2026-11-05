<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-036, D-097: payment method settings. */
class PaymentMethodSettingsTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_owner_renames_and_turns_off_a_method_with_an_audit_entry(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $gcash = PaymentMethod::query()->where('code', 'GCASH')->sole();

        $this->actingAs($owner)->get('/admin/payment-methods')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/payment-methods/Index')->has('methods', 2));

        $this->actingAs($owner)->put("/admin/payment-methods/{$gcash->id}", ['name' => 'GCash QR', 'is_active' => false, 'requires_reference' => true])
            ->assertSessionHasNoErrors();

        $gcash->refresh();
        $this->assertSame(['GCash QR', false], [$gcash->name, $gcash->is_active]);
        $audit = AuditLog::query()->where('event', 'payment_method.updated')->sole();
        $this->assertSame(['name' => 'GCash QR', 'is_active' => false], $audit->new_values);
    }

    public function test_the_last_active_method_cannot_be_turned_off(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        PaymentMethod::query()->where('code', 'GCASH')->update(['is_active' => false]);
        $cash = PaymentMethod::query()->where('code', 'CASH')->sole();

        $this->actingAs($owner)->put("/admin/payment-methods/{$cash->id}", ['name' => 'Cash', 'is_active' => false, 'requires_reference' => false])
            ->assertSessionHasErrors(['is_active' => 'Keep at least one payment method active.']);
        $this->assertTrue($cash->refresh()->is_active);
    }

    public function test_cash_cannot_require_a_reference(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $cash = PaymentMethod::query()->where('code', 'CASH')->sole();

        $this->actingAs($owner)->put("/admin/payment-methods/{$cash->id}", ['name' => 'Cash', 'is_active' => true, 'requires_reference' => true])
            ->assertSessionHasErrors('requires_reference');
    }

    public function test_only_settings_managers_can_change_methods(): void
    {
        $manager = $this->staff(PermissionCatalog::MANAGER);
        $cash = PaymentMethod::query()->where('code', 'CASH')->sole();

        $this->actingAs($manager)->put("/admin/payment-methods/{$cash->id}", ['name' => 'X', 'is_active' => true, 'requires_reference' => false])->assertForbidden();
    }
}
