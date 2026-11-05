<?php

namespace Tests\Feature\Locations;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Locations\LocationContext;
use App\Enums\LocationType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-011, FR-103, FR-104, FR-106, FR-108; D-059–D-065 · T-LOC-3..9 */
class PopUpManagementTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function popUp(array $overrides = []): array
    {
        return ['code' => 'pop-smr1', 'name' => 'Pop-up — SM Rosales', 'city_municipality' => 'Rosales', 'copy_availability' => false, ...$overrides];
    }

    public function test_admin_creates_a_pop_up_and_it_is_audited(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);

        $response = $this->actingAs($admin)->post('/admin/locations', $this->popUp(['operating_starts_on' => '2026-12-01', 'operating_ends_on' => '2026-12-31']));

        $location = Branch::query()->where('code', 'POP-SMR1')->firstOrFail();
        $response->assertRedirect("/admin/locations/{$location->id}/edit");
        $this->assertSame(LocationType::PopUp, $location->location_type);
        $this->assertSame('2026-12-31', $location->operating_ends_on?->toDateString());
        $this->assertSame(1, Branch::query()->where('location_type', LocationType::Permanent)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'location.created', 'auditable_id' => $location->id, 'user_id' => $admin->id]);
    }

    public function test_pop_up_input_is_validated(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);

        $this->actingAs($admin)->post('/admin/locations', $this->popUp(['code' => 'BRV01']))->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/locations', $this->popUp(['code' => 'bad code!']))->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/locations', $this->popUp(['operating_starts_on' => '2026-12-10', 'operating_ends_on' => '2026-12-01']))
            ->assertSessionHasErrors('operating_ends_on');
        // The type cannot be chosen: a second permanent branch is impossible through the app.
        $this->actingAs($admin)->post('/admin/locations', $this->popUp(['location_type' => 'permanent']));
        $this->assertSame(1, Branch::query()->where('location_type', LocationType::Permanent)->count());
    }

    public function test_code_and_type_cannot_change_and_edits_are_audited(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);

        $this->actingAs($admin)->put("/admin/locations/{$popUp->id}", ['code' => 'POP-B', 'name' => 'X'])->assertSessionHasErrors('code');

        $this->actingAs($admin)->put("/admin/locations/{$popUp->id}", ['name' => 'Renamed', 'operating_ends_on' => '2026-12-24'])->assertSessionHasNoErrors();

        $popUp->refresh();
        $this->assertSame('POP-A', $popUp->code);
        $this->assertSame('Renamed', $popUp->name);
        $log = AuditLog::query()->where('event', 'location.updated')->firstOrFail();
        $this->assertSame('Renamed', $log->new_values['name'] ?? null);
        $this->assertSame('2026-12-24', $log->new_values['operating_ends_on'] ?? null);
    }

    public function test_the_permanent_branch_has_no_dates_and_cannot_be_deactivated(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $main = $this->mainLocation();

        $this->actingAs($admin)->put("/admin/locations/{$main->id}", ['name' => 'Main', 'operating_ends_on' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->assertNull($main->refresh()->operating_ends_on);

        $this->actingAs($admin)->post("/admin/locations/{$main->id}/deactivate", ['reason' => 'Testing it'])->assertSessionHasErrors('reason');
        $this->assertTrue($main->refresh()->is_active);
    }

    public function test_deactivating_a_pop_up_keeps_its_records_and_blocks_new_operations(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $popUp = Branch::factory()->popUp()->create();
        $terminal = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall']);

        $this->actingAs($admin)->post("/admin/locations/{$popUp->id}/deactivate", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/admin/locations/{$popUp->id}/deactivate", ['reason' => 'Mall event ended'])->assertSessionHasNoErrors();

        $popUp->refresh();
        $this->assertFalse($popUp->is_active);
        $this->assertSame('Mall event ended', $popUp->deactivation_reason);
        $this->assertSame($admin->id, $popUp->deactivated_by);
        $this->assertFalse($popUp->acceptsNewOperationsOn(CarbonImmutable::now('Asia/Manila')));
        $this->assertTrue($terminal->refresh()->exists);
        $this->assertDatabaseHas('audit_logs', ['event' => 'location.deactivated', 'auditable_id' => $popUp->id]);

        $this->actingAs($admin)->post("/admin/locations/{$popUp->id}/reactivate")->assertSessionHasNoErrors();
        $this->assertTrue($popUp->refresh()->is_active);
        $this->assertNull($popUp->deactivation_reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'location.reactivated', 'auditable_id' => $popUp->id]);
    }

    public function test_copying_availability_only_brings_finished_stock_products(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $main = $this->mainLocation();
        $doughnut = Product::factory()->finishedStock()->create();
        $coffee = Product::factory()->create();
        $notAtMain = Product::factory()->finishedStock()->create();
        $doughnut->locations()->attach($main->id, ['is_available' => true]);
        $coffee->locations()->attach($main->id, ['is_available' => true]);

        $this->actingAs($admin)->post('/admin/locations', $this->popUp(['copy_availability' => true]));

        $popUp = Branch::query()->where('code', 'POP-SMR1')->firstOrFail();
        $this->assertEqualsCanonicalizing([$doughnut->id], $popUp->products()->wherePivot('is_available', true)->pluck('products.id')->all());
        $this->assertFalse($notAtMain->locations()->where('branches.id', $popUp->id)->exists());
    }

    public function test_out_of_period_pop_ups_cannot_be_selected_as_working_location(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 02:00:00'); // 10:00 Manila
        $scheduled = Branch::factory()->popUp()->create(['operating_starts_on' => '2026-11-06']);
        $running = Branch::factory()->popUp()->create(['operating_starts_on' => '2026-11-05', 'operating_ends_on' => '2026-11-05']);
        $manager = $this->staff(PermissionCatalog::MANAGER);
        $manager->branches()->attach([$scheduled->id => ['is_default' => false], $running->id => ['is_default' => false]]);

        $available = $this->app->make(LocationContext::class)->available($manager)->pluck('id')->all();

        $this->assertContains($running->id, $available);
        $this->assertNotContains($scheduled->id, $available);
        $this->actingAs($manager)->post('/location', ['branch_id' => $scheduled->id])->assertForbidden();
        CarbonImmutable::setTestNow();
    }

    public function test_managers_cannot_manage_locations(): void
    {
        $manager = $this->staff(PermissionCatalog::MANAGER);

        $this->actingAs($manager)->post('/admin/locations', $this->popUp())->assertForbidden();
        $this->assertDatabaseMissing('branches', ['code' => 'POP-SMR1']);
    }

    public function test_location_edit_page_keeps_the_shared_location_context(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $this->actingAs($admin)->post('/admin/locations', $this->popUp());
        $popUp = Branch::query()->where('code', 'POP-SMR1')->firstOrFail();

        // Regression: a page prop named "location" used to override the shared location context.
        $this->actingAs($admin)->get("/admin/locations/{$popUp->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/locations/Form')
                ->where('site.code', 'POP-SMR1')
                ->where('location.current.code', $this->mainLocation()->code));
    }
}
