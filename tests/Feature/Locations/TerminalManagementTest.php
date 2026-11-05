<?php

namespace Tests\Feature\Locations;

use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-106, D-060, N-12 · T-LOC-6..7 */
class TerminalManagementTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_terminal_codes_are_assigned_in_order_across_all_locations(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $popUp = Branch::factory()->popUp()->create();
        Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'STALL-A', 'name' => 'Older custom code']);

        // Main already has POS-1 (seeded). A code sent with the form is ignored (D-122).
        $this->actingAs($admin)->post("/admin/locations/{$popUp->id}/terminals", ['terminal_code' => 'MINE', 'name' => 'Stall'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/locations/{$this->mainLocation()->id}/terminals", ['name' => 'Second counter'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/locations/{$popUp->id}/terminals", ['name' => ''])->assertSessionHasErrors('name');

        $this->assertSame('POS-2', Terminal::query()->where('name', 'Stall')->value('terminal_code'));
        $this->assertSame('POS-3', Terminal::query()->where('name', 'Second counter')->value('terminal_code'));
        $this->assertDatabaseMissing('terminals', ['terminal_code' => 'MINE']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'terminal.created', 'branch_id' => $popUp->id]);
    }

    public function test_reassignment_is_audited_and_only_to_operating_locations(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $terminal = $this->mainLocation()->terminals()->firstOrFail();
        $closed = Branch::factory()->popUp()->inactive()->create();
        $future = Branch::factory()->popUp()->create(['operating_starts_on' => now('Asia/Manila')->addDays(3)->toDateString()]);
        $open = Branch::factory()->popUp()->create();

        $this->actingAs($admin)->post("/admin/terminals/{$terminal->id}/reassign", ['branch_id' => $closed->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post("/admin/terminals/{$terminal->id}/reassign", ['branch_id' => $future->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post("/admin/terminals/{$terminal->id}/reassign", ['branch_id' => $terminal->branch_id])->assertSessionHasErrors('branch_id');

        $this->actingAs($admin)->post("/admin/terminals/{$terminal->id}/reassign", ['branch_id' => $open->id])->assertSessionHasNoErrors();

        $this->assertSame($open->id, $terminal->refresh()->branch_id);
        $log = AuditLog::query()->where('event', 'terminal.reassigned')->firstOrFail();
        $this->assertSame(['location' => 'BRV01'], $log->old_values);
        $this->assertSame(['location' => $open->code], $log->new_values);
    }

    /** Only possible with codes typed before D-122; new codes never clash. */
    public function test_reassignment_refuses_a_code_clash_at_the_destination(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $terminal = $this->mainLocation()->terminals()->firstOrFail();
        $popUp = Branch::factory()->popUp()->create();
        Terminal::query()->create(['branch_id' => $popUp->id, 'terminal_code' => 'POS-1', 'name' => 'Stall']);

        $this->actingAs($admin)->post("/admin/terminals/{$terminal->id}/reassign", ['branch_id' => $popUp->id])->assertSessionHasErrors('branch_id');
        $this->assertSame($this->mainLocation()->id, $terminal->refresh()->branch_id);
    }

    public function test_terminal_rename_and_deactivation_are_audited(): void
    {
        $admin = $this->staff(PermissionCatalog::ADMIN);
        $terminal = $this->mainLocation()->terminals()->firstOrFail();

        $this->actingAs($admin)->put("/admin/terminals/{$terminal->id}", ['name' => 'Window', 'is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($terminal->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'terminal.updated', 'auditable_id' => $terminal->id]);
    }
}
