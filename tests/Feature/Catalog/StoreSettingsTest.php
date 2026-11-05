<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-013, FR-014, FR-101 · T-SET-1..3 */
class StoreSettingsTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_settings_have_safe_defaults_with_fixed_currency_and_time_zone(): void
    {
        $this->seedAccess();

        $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page->component('admin/settings/Store')
                ->where('settings.name', 'Brevon Doughnuts & Coffee')
                ->where('settings.currency', 'PHP')
                ->where('settings.timezone', 'Asia/Manila'));
    }

    public function test_owner_updates_name_footer_and_logo_and_it_is_audited(): void
    {
        $this->seedAccess();
        Storage::fake('public');
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($owner)->put('/admin/settings', [
            'name' => 'Brevon Rosales',
            'receipt_footer' => 'Salamat po!',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
            // Currency/time zone are not accepted from the form.
            'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.name', 'Brevon Rosales')
                ->where('settings.receipt_footer', 'Salamat po!')
                ->where('settings.currency', 'PHP')
                ->where('store.name', 'Brevon Rosales')
                ->whereNot('store.logo_url', null));

        $this->assertSame(3, AuditLog::query()->where('event', 'setting.updated')->count());
        $this->assertCount(1, Storage::disk('public')->files('branding'));

        $this->actingAs($owner)->put('/admin/settings', ['name' => 'Brevon Rosales', 'receipt_footer' => 'Salamat po!', 'remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertCount(0, Storage::disk('public')->files('branding'));
    }

    public function test_non_images_and_unauthorised_users_are_refused(): void
    {
        $this->seedAccess();
        Storage::fake('public');

        $this->actingAs($this->staff(PermissionCatalog::OWNER))->put('/admin/settings', [
            'name' => 'X', 'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('logo');

        $this->actingAs($this->staff(PermissionCatalog::MANAGER))->put('/admin/settings', ['name' => 'Hacked'])->assertForbidden();
    }
}
