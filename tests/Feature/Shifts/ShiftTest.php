<?php

namespace Tests\Feature\Shifts;

use App\Domain\Access\PermissionCatalog;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Terminal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-073, FR-074, FR-078, FR-079, FR-106, FR-107; D-027, D-059, D-060, D-062, D-094 · T-SHF-1, 3, 4; T-LOC-5, 8 */
class ShiftTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private Terminal $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->counter = Terminal::query()->where('terminal_code', 'POS-1')->firstOrFail();
    }

    private function open(User $cashier, Terminal $terminal, string $cash = '1000.00'): TestResponse
    {
        return $this->actingAs($cashier)->post('/staff/shift', ['terminal_id' => $terminal->id, 'opening_cash' => $cash]);
    }

    public function test_cashier_opens_a_shift_on_a_terminal(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 23:30:00'); // 07:30 on 6 Nov in Manila
        $cashier = $this->staff(PermissionCatalog::CASHIER);

        $this->open($cashier, $this->counter, '1500.50')->assertSessionHasNoErrors()->assertRedirect('/staff/shift');

        $shift = CashierShift::query()->sole();
        $this->assertSame($this->mainLocation()->id, $shift->branch_id);
        $this->assertSame($this->counter->id, $shift->terminal_id);
        $this->assertSame('BRV01-20261106-S01', $shift->shift_number);
        $this->assertSame('2026-11-06', $shift->business_date->toDateString());
        $this->assertSame('1500.50', $shift->opening_cash);
        $this->assertSame(ShiftStatus::Open, $shift->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'shift.opened', 'auditable_id' => $shift->id, 'user_id' => $cashier->id]);

        $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($page) => $page
            ->where('activeShift.number', 'BRV01-20261106-S01')
            ->where('activeShift.terminal', 'POS-1'));
        CarbonImmutable::setTestNow();
    }

    public function test_location_comes_from_the_terminal_not_the_request(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);
        $stall = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $cashier = $this->staff(PermissionCatalog::CASHIER, $popUp);

        $this->actingAs($cashier)->post('/staff/shift', [
            'terminal_id' => $stall->id, 'opening_cash' => '500', 'branch_id' => $this->mainLocation()->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($popUp->id, CashierShift::query()->sole()->branch_id);
    }

    public function test_shift_numbers_count_per_location_and_day(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);
        $stall = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $second = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Second', 'is_active' => true]);

        $this->open($this->staff(PermissionCatalog::CASHIER), $this->counter);
        $this->open($this->staff(PermissionCatalog::CASHIER), $second);
        $this->open($this->staff(PermissionCatalog::CASHIER, $popUp), $stall);

        $numbers = CashierShift::query()->orderBy('id')->pluck('shift_number')->all();
        $today = now('Asia/Manila')->format('Ymd');
        $this->assertSame(["BRV01-{$today}-S01", "BRV01-{$today}-S02", "POP-A-{$today}-S01"], $numbers);
    }

    public function test_one_open_shift_per_cashier_and_per_terminal(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $other = $this->staff(PermissionCatalog::CASHIER);
        $second = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Second', 'is_active' => true]);

        $this->open($cashier, $this->counter)->assertSessionHasNoErrors();
        $this->open($cashier, $second)->assertSessionHasErrors('terminal_id');
        $this->open($other, $this->counter)->assertSessionHasErrors('terminal_id');
        $this->open($other, $second)->assertSessionHasNoErrors();

        $this->assertSame(2, CashierShift::query()->open()->count());
    }

    public function test_database_refuses_a_second_open_shift_even_without_the_service(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $this->open($cashier, $this->counter);
        $second = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Second', 'is_active' => true]);

        $this->expectException(UniqueConstraintViolationException::class);
        $shift = new CashierShift;
        $shift->forceFill([
            'branch_id' => $this->mainLocation()->id, 'terminal_id' => $second->id, 'user_id' => $cashier->id,
            'shift_number' => 'MANUAL-1', 'business_date' => '2026-11-05', 'opened_at' => now(), 'opening_cash' => '0', 'status' => 'open',
        ])->save();
    }

    public function test_shift_opening_is_blocked_at_closed_or_out_of_period_pop_ups(): void
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $cases = [
            'inactive' => Branch::factory()->popUp()->inactive()->create(['code' => 'POP-X']),
            'not started' => Branch::factory()->popUp($today->addDay()->toDateString())->create(['code' => 'POP-Y']),
            'ended' => Branch::factory()->popUp(null, $today->subDay()->toDateString())->create(['code' => 'POP-Z']),
        ];

        foreach ($cases as $label => $popUp) {
            $terminal = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
            $cashier = $this->staff(PermissionCatalog::CASHIER, $popUp);

            $this->open($cashier, $terminal)->assertSessionHasErrors('terminal_id');
            $this->assertSame(0, CashierShift::query()->count(), $label);
        }

        // Last day of the period is still allowed (inclusive, Manila date).
        $lastDay = Branch::factory()->popUp($today->subDays(3)->toDateString(), $today->toDateString())->create(['code' => 'POP-L']);
        $terminal = Terminal::query()->create(['branch_id' => $lastDay->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $this->open($this->staff(PermissionCatalog::CASHIER, $lastDay), $terminal)->assertSessionHasNoErrors();
    }

    public function test_cashier_cannot_open_at_an_unassigned_location_or_on_an_inactive_terminal(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);
        $stall = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $cashier = $this->staff(PermissionCatalog::CASHIER); // main branch only

        $this->open($cashier, $stall)->assertSessionHasErrors('terminal_id');

        $this->counter->forceFill(['is_active' => false])->save();
        $this->open($cashier, $this->counter)->assertSessionHasErrors('terminal_id');
        $this->assertSame(0, CashierShift::query()->count());

        $this->actingAs($cashier)->get('/staff/shift')->assertInertia(fn ($page) => $page->component('pos/Shift')->has('terminals', 0));
    }

    public function test_preparation_staff_cannot_open_a_shift(): void
    {
        $this->open($this->staff($this->bakerRole()), $this->counter)->assertForbidden();
    }

    public function test_cashier_closes_their_shift_with_a_cash_count(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $this->open($cashier, $this->counter, '1000.00');
        $shift = CashierShift::query()->sole();

        $this->actingAs($cashier)->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '985.25', 'closing_notes' => 'Short a coin roll'])
            ->assertSessionHasNoErrors()->assertRedirect('/staff/shift');

        $shift->refresh();
        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame('1000.00', $shift->expected_cash);
        $this->assertSame('985.25', $shift->counted_cash);
        $this->assertSame('-14.75', $shift->variance);
        $this->assertSame($cashier->id, $shift->closed_by);
        $this->assertNull($shift->getAttribute('open_guard'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'shift.closed', 'auditable_id' => $shift->id]);

        // Closed shifts are immutable and cannot be closed twice; the terminal is free again.
        $this->actingAs($cashier)->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1000'])->assertSessionHasErrors('counted_cash');
        $this->open($cashier, $this->counter)->assertSessionHasNoErrors();

        $this->expectException(LogicException::class);
        $shift->forceFill(['counted_cash' => '1000.00'])->save();
    }

    public function test_only_the_owner_or_a_reviewer_can_close_a_shift(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $this->open($cashier, $this->counter);
        $shift = CashierShift::query()->sole();

        $this->actingAs($this->staff(PermissionCatalog::CASHIER))->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1000'])
            ->assertSessionHasErrors('counted_cash');
        $this->assertTrue($shift->refresh()->isOpen());

        $this->actingAs($this->staff(PermissionCatalog::MANAGER))->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1000'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($shift->refresh()->isOpen());
    }

    public function test_a_shift_left_open_after_the_pop_up_ended_can_still_be_closed(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);
        $stall = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $cashier = $this->staff(PermissionCatalog::CASHIER, $popUp);
        $this->open($cashier, $stall);
        $shift = CashierShift::query()->sole();

        // The operating period ends while the shift is still open.
        $popUp->forceFill(['operating_ends_on' => CarbonImmutable::now('Asia/Manila')->subDays(1)->toDateString(), 'operating_starts_on' => null])->save();

        $this->actingAs($cashier)->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1000'])->assertSessionHasNoErrors();
        $this->assertFalse($shift->refresh()->isOpen());
    }

    public function test_open_shifts_block_location_deactivation_and_terminal_changes(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-A']);
        $stall = Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall', 'is_active' => true]);
        $cashier = $this->staff(PermissionCatalog::CASHIER, $popUp);
        $this->open($cashier, $stall);

        $this->actingAs($owner)->post("/admin/locations/{$popUp->id}/deactivate", ['reason' => 'Season over'])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->put("/admin/terminals/{$stall->id}", ['name' => 'Stall', 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->actingAs($owner)->post("/admin/terminals/{$stall->id}/reassign", ['branch_id' => $this->mainLocation()->id])->assertSessionHasErrors('branch_id');
        // Renaming is still fine.
        $this->actingAs($owner)->put("/admin/terminals/{$stall->id}", ['name' => 'Stall A', 'is_active' => true])->assertSessionHasNoErrors();

        $this->assertTrue($popUp->refresh()->is_active);
        $this->assertTrue($stall->refresh()->is_active);
        $this->assertSame($popUp->id, $stall->branch_id);

        $shift = CashierShift::query()->sole();
        $this->actingAs($cashier)->post("/staff/shifts/{$shift->id}/close", ['counted_cash' => '1000']);
        $this->actingAs($owner)->post("/admin/locations/{$popUp->id}/deactivate", ['reason' => 'Season over'])->assertSessionHasNoErrors();
        $this->assertFalse($popUp->refresh()->is_active);
    }

    public function test_shifts_cannot_be_deleted(): void
    {
        $this->open($this->staff(PermissionCatalog::CASHIER), $this->counter);

        $this->expectException(LogicException::class);
        CashierShift::query()->sole()->delete();
    }
}
