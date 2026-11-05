<?php

namespace Tests\Feature\Locations;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Locations\LocationContext;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-103..FR-106, D-054, D-057..D-060 · T-LOC-1..5 */
class LocationAccessTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_seed_creates_exactly_one_permanent_location_and_no_pop_ups(): void
    {
        $this->seedAccess();

        $this->assertSame(1, Branch::query()->where('location_type', LocationType::Permanent)->count());
        $this->assertSame(0, Branch::query()->where('location_type', LocationType::PopUp)->count());
        $this->assertSame(1, Terminal::query()->count());
    }

    public function test_database_refuses_a_second_permanent_location(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('branches_permanent_guard_unique');

        Branch::factory()->permanent()->create(['code' => 'BRV02']);
    }

    public function test_database_refuses_an_operating_period_that_ends_before_it_starts(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('branches_operating_period_check');

        Branch::factory()->popUp()->create(['operating_starts_on' => '2026-12-10', 'operating_ends_on' => '2026-12-01']);
    }

    public function test_locations_and_terminals_are_never_deleted(): void
    {
        $main = $this->mainLocation();

        try {
            $main->delete();
            $this->fail('Locations must be deactivated, not deleted.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        Terminal::query()->firstOrFail()->delete();
    }

    public function test_pop_up_accepts_operations_only_while_active_and_within_optional_dates(): void
    {
        $day = CarbonImmutable::parse('2026-12-05', 'Asia/Manila');

        $this->assertTrue(Branch::factory()->popUp()->create()->acceptsNewOperationsOn($day));
        $this->assertTrue(Branch::factory()->popUp()->create(['operating_starts_on' => '2026-12-01', 'operating_ends_on' => '2026-12-05'])->acceptsNewOperationsOn($day));
        $this->assertFalse(Branch::factory()->popUp()->create(['operating_starts_on' => '2026-12-06'])->acceptsNewOperationsOn($day));
        $this->assertFalse(Branch::factory()->popUp()->create(['operating_ends_on' => '2026-12-04'])->acceptsNewOperationsOn($day));
        $this->assertFalse(Branch::factory()->popUp()->inactive()->create()->acceptsNewOperationsOn($day));
        $this->assertTrue($this->mainLocation()->acceptsNewOperationsOn($day));
    }

    public function test_users_can_switch_only_to_active_assigned_locations(): void
    {
        $popUp = Branch::factory()->popUp()->create();
        $closed = Branch::factory()->popUp()->inactive()->create();
        $unassigned = Branch::factory()->popUp()->create();
        $manager = $this->staff(PermissionCatalog::MANAGER);
        $manager->branches()->attach([$popUp->id => ['is_default' => false], $closed->id => ['is_default' => false]]);

        $this->actingAs($manager)->post('/location', ['branch_id' => $popUp->id])->assertRedirect();
        $this->assertSame($popUp->id, session(LocationContext::SESSION_KEY));

        $this->actingAs($manager)->post('/location', ['branch_id' => $closed->id])->assertForbidden();
        $this->actingAs($manager)->post('/location', ['branch_id' => $unassigned->id])->assertForbidden();
        $this->actingAs($manager)->post('/location', ['branch_id' => 999999])->assertForbidden();
        $this->assertSame($popUp->id, session(LocationContext::SESSION_KEY));
    }

    public function test_current_location_is_shared_and_falls_back_when_a_location_closes(): void
    {
        $popUp = Branch::factory()->popUp()->create();
        $manager = $this->staff(PermissionCatalog::MANAGER);
        $manager->branches()->attach($popUp->id, ['is_default' => false]);

        $this->actingAs($manager)->withSession([LocationContext::SESSION_KEY => $popUp->id])->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page->where('location.current.id', $popUp->id)->has('location.available', 2));

        $popUp->forceFill(['is_active' => false])->save();

        $this->actingAs($manager)->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page->where('location.current.id', $this->mainLocation()->id)->has('location.available', 1));
    }
}
