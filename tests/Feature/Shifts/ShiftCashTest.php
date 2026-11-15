<?php

namespace Tests\Feature\Shifts;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Shifts\ExpectedCash;
use App\Domain\Shifts\ShiftSummary;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 6: T-CSH-1..5, review and corrections (FR-075–FR-082, BR-007, BR-008, UC-008; D-126–D-130). */
class ShiftCashTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $cashier;

    private User $manager;

    private Product $doughnut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->doughnut = $this->doughnut('44.00', '50');
        $this->cashier = $this->cashierOnShift(openingCash: '1000.00');
        $this->manager = $this->staff(PermissionCatalog::MANAGER, $this->mainLocation());
    }

    private function shift(): CashierShift
    {
        return $this->shiftOf($this->cashier)->refresh();
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function sell(int $quantity = 2, array $payment = ['method' => 'CASH', 'amount_tendered' => '1000']): Order
    {
        $this->checkout($this->cashier, $this->payload([['product_id' => $this->doughnut->id, 'quantity' => $quantity]], $payment))
            ->assertSessionHasNoErrors()->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function record(User $user, string $type, string $amount, string $reason = 'Coins for change', ?CashierShift $shift = null): TestResponse
    {
        $shift ??= $this->shift();

        return $this->actingAs($user)->post("/shifts/{$shift->id}/cash-movements", ['movement_type' => $type, 'amount' => $amount, 'reason' => $reason]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function close(User $user, string $counted, ?string $notes = null): TestResponse
    {
        return $this->actingAs($user)->post("/staff/shifts/{$this->shift()->id}/close", ['counted_cash' => $counted, 'closing_notes' => $notes]);
    }

    /** T-CSH-1, T-CSH-2: opening + cash sales + cash in − cash out − paid outs; GCash and cancelled sales never count. */
    public function test_expected_cash_follows_the_formula_and_leaves_out_gcash_and_cancelled_sales(): void
    {
        $this->sell(2);                                                        // + 88.00
        $this->sell(1, ['method' => 'GCASH', 'reference' => 'GC12340001']);   // GCash: not in the drawer
        $this->sell(1, ['method' => 'GCASH', 'reference' => 'GC12340002']);
        $latte = $this->coffee();
        $this->checkout($this->cashier, $this->payload([['product_id' => $latte['product']->id, 'variant_id' => $latte['iced']->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $cancelled = Order::query()->latest('id')->firstOrFail();             // + 110.00, then cancelled (before D-169)
        $this->pastCancellation($cancelled, $this->manager, 'Changed mind');

        $this->record($this->manager, 'cash_in', '200.00')->assertSessionHasNoErrors();
        $this->record($this->manager, 'cash_out', '100.00', 'Safe drop')->assertSessionHasNoErrors();
        $this->record($this->manager, 'cash_paid_out', '50.00', 'Ice')->assertSessionHasNoErrors();

        // 1000 + 88 + 200 − 100 − 50
        $this->assertSame('1138.00', app(ExpectedCash::class)->for($this->shift())->toDecimal());

        $summary = app(ShiftSummary::class)->build($this->shift());
        $this->assertSame('88.00', $summary['cash_sales']);
        $this->assertSame('110.00', $summary['cancelled_cash']);
        $this->assertSame(['200.00', '100.00', '50.00', '0.00'], [$summary['cash_in'], $summary['cash_out'], $summary['paid_out'], $summary['refunds']]);
        $this->assertSame([['method' => 'GCash', 'verified' => '88.00']], $summary['non_cash']);
        $this->assertSame(4, $summary['order_count']);
        $this->assertSame(1, $summary['cancelled_count']);

        $this->close($this->cashier, '1138.00')->assertSessionHasNoErrors();
        $shift = $this->shift();
        $this->assertSame('1138.00', $shift->expected_cash);
        $this->assertSame('0.00', $shift->variance);
    }

    /** T-CSH-3: variance = counted − expected, and the cashier never sees the expected amount. */
    public function test_a_count_that_does_not_match_needs_a_recount_or_an_explanation(): void
    {
        $this->sell(2); // expected 1088.00

        $this->close($this->cashier, '1075.50')->assertSessionHasErrors('closing_notes');
        $message = session('errors')->first('closing_notes');
        $this->assertStringNotContainsString('1,088', $message);
        $this->assertStringNotContainsString('12.50', $message);
        $this->assertTrue($this->shift()->isOpen());

        // The cashier's page for an open shift carries no expected figure.
        $this->actingAs($this->cashier)->get('/staff/shift')->assertOk()
            ->assertInertia(fn ($page) => $page->where('shift.expected_cash', null)->missing('summary'));

        $this->close($this->cashier, '1075.50', 'Gave too much change once')->assertSessionHasNoErrors()->assertRedirect('/staff/shift');
        $shift = $this->shift();
        $this->assertSame('1088.00', $shift->expected_cash);
        $this->assertSame('1075.50', $shift->counted_cash);
        $this->assertSame('-12.50', $shift->variance);
    }

    /** T-CSH-5: a movement needs a type, amount and reason, and only on an open shift. */
    public function test_cash_movements_need_a_reason_and_an_open_shift(): void
    {
        $this->record($this->manager, 'cash_in', '100.00', '')->assertSessionHasErrors('reason');
        $this->record($this->manager, 'cash_in', '0')->assertSessionHasErrors('amount');
        $this->record($this->manager, 'correction', '10.00')->assertSessionHasErrors('movement_type');
        $this->record($this->manager, 'cash_refund', '10.00')->assertSessionHasErrors('movement_type');

        // More than the drawer holds cannot come out.
        $this->record($this->manager, 'cash_out', '1000.01')->assertSessionHasErrors('amount');

        // Cashiers have no cash-movement permission by default.
        $this->record($this->cashier, 'cash_in', '100.00')->assertForbidden();

        $this->record($this->manager, 'cash_in', '100.00')->assertSessionHasNoErrors();
        $movement = CashMovement::query()->sole();
        $this->assertNull($movement->approved_by, 'Nothing needs approving (D-193).');
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash_movement.recorded', 'auditable_id' => $movement->id]);

        $this->close($this->cashier, '1100.00')->assertSessionHasNoErrors();
        $this->record($this->manager, 'cash_in', '100.00')->assertSessionHasErrors('amount');
    }

    /** D-193: cash out of any size counts at once; there is no approval step any more. */
    public function test_every_cash_movement_counts_at_once(): void
    {
        $this->cashier->givePermissionTo('cash-movements.create');

        $this->record($this->cashier, 'cash_out', '600.00', 'Safe drop')->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $this->record($this->manager, 'cash_in', '400.00', 'Change float')->assertSessionHasNoErrors();
        $this->record($this->cashier, 'cash_paid_out', '500.01', 'Ice')->assertSessionHasNoErrors();
        $this->assertSame('299.99', app(ExpectedCash::class)->for($this->shift())->toDecimal());

        // More than the drawer holds still cannot come out.
        $this->record($this->cashier, 'cash_out', '300.00')->assertSessionHasErrors('amount');

        $movement = CashMovement::query()->orderBy('id')->firstOrFail();
        $this->post("/cash-movements/{$movement->id}/approve")->assertNotFound();
        $this->assertNull(DB::table('cash_movements')->first()?->approved_by);
        $this->assertFalse(Schema::hasColumn('cash_movements', 'status'));

        $this->close($this->cashier, '299.99')->assertSessionHasNoErrors();

        // Movements are never edited or deleted.
        $this->expectException(LogicException::class);
        $movement->forceFill(['amount' => '1.00'])->save();
    }

    public function test_a_cashier_cannot_record_cash_on_someone_elses_shift(): void
    {
        $this->cashier->givePermissionTo('cash-movements.create');
        $other = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());
        $other->givePermissionTo('cash-movements.create');

        $this->record($other, 'cash_in', '50.00')->assertSessionHasErrors('amount');
    }

    /** T-CSH-4: a closed shift never changes; corrections sit beside it, audited. */
    public function test_closed_shifts_are_corrected_with_audited_rows_not_edits(): void
    {
        $this->sell(2);
        $this->close($this->cashier, '1038.00', 'Paid 50 for ice, forgot to record')->assertSessionHasNoErrors();
        $shift = $this->shift();
        $this->assertSame('-50.00', $shift->variance);

        try {
            $shift->forceFill(['counted_cash' => '1088.00'])->save();
            $this->fail('A closed shift must not be editable.');
        } catch (LogicException) {
        }

        // Only reviewers correct, never on a shift they worked, never on an open shift.
        $this->actingAs($this->cashier)->post("/admin/shifts/{$shift->id}/corrections", ['corrects' => 'expected', 'amount' => '-50.00', 'reason' => 'Ice'])->assertForbidden();
        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/corrections", ['corrects' => 'expected', 'amount' => '-50.00', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/corrections", ['corrects' => 'expected', 'amount' => '0', 'reason' => 'Nothing'])->assertSessionHasErrors('amount');

        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/corrections", ['corrects' => 'expected', 'amount' => '-50.00', 'reason' => 'Unrecorded paid-out for ice'])->assertSessionHasNoErrors();

        $shift->refresh();
        $this->assertSame(['1088.00', '1038.00', '-50.00'], [$shift->expected_cash, $shift->counted_cash, $shift->variance], 'Closing figures never change.');
        $summary = app(ShiftSummary::class)->build($shift);
        $this->assertSame('-50.00', $summary['expected_correction']);
        $this->assertSame('1038.00', $summary['corrected_expected']);
        $this->assertSame('0.00', $summary['corrected_variance']);
        $this->assertFalse($summary['needs_review']);

        $log = AuditLog::query()->where('event', 'shift.corrected')->sole();
        $this->assertSame($shift->id, $log->auditable_id);
        $this->assertSame($this->manager->id, $log->user_id);

        // A manager who worked the shift cannot correct it.
        $managerShift = $this->openShiftFor($this->manager);
        $this->actingAs($this->manager)->post("/staff/shifts/{$managerShift->id}/close", ['counted_cash' => '100.00'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/admin/shifts/{$managerShift->id}/corrections", ['corrects' => 'counted', 'amount' => '5.00', 'reason' => 'Mine'])->assertSessionHasErrors('amount');
    }

    public function test_a_correction_cannot_be_added_to_an_open_shift(): void
    {
        $this->actingAs($this->manager)->post("/admin/shifts/{$this->shift()->id}/corrections", ['corrects' => 'counted', 'amount' => '5.00', 'reason' => 'Early'])->assertSessionHasErrors('amount');
        $this->assertSame(0, CashMovement::query()->count());
    }

    /** FR-080, D-128: a manager signs off once, with a note when there is a difference. */
    public function test_managers_review_closed_shifts_once(): void
    {
        $this->sell(2);
        $this->close($this->cashier, '1080.00', 'Short')->assertSessionHasNoErrors();
        $shift = $this->shift();

        $this->actingAs($this->manager)->get('/admin/shifts?status=needs_review')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/shifts/Index')->has('shifts.data', 1)->where('shifts.data.0.needs_review', true));

        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/review", ['review_notes' => ''])->assertSessionHasErrors('review_notes');
        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/review", ['review_notes' => 'Cashier paid back the 8.00'])->assertSessionHasNoErrors();

        $shift->refresh();
        $this->assertSame($this->manager->id, $shift->approved_by);
        $this->assertNotNull($shift->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'shift.reviewed', 'auditable_id' => $shift->id]);
        $this->actingAs($this->manager)->post("/admin/shifts/{$shift->id}/review", ['review_notes' => 'Again'])->assertSessionHasErrors('review_notes');

        $this->actingAs($this->manager)->get('/admin/shifts?status=needs_review')->assertOk()
            ->assertInertia(fn ($page) => $page->has('shifts.data', 0));

        $this->actingAs($this->manager)->get("/admin/shifts/{$shift->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/shifts/Show')
                ->where('summary.variance', '-8.00')
                ->where('summary.needs_review', false)
                ->where('can.review', false)
                ->where('can.correct', true));
    }

    /** D-193: the review limit setting is gone; any difference needs review. */
    public function test_any_difference_needs_review_and_open_shifts_cannot_be_reviewed(): void
    {
        $this->actingAs($this->manager)->post("/admin/shifts/{$this->shift()->id}/review")->assertSessionHasErrors('review_notes');

        $this->close($this->cashier, '999.99', 'Short a centavo')->assertSessionHasNoErrors();
        $this->assertTrue(app(ShiftSummary::class)->needsReview($this->shift()));
        $this->actingAs($this->manager)->post("/admin/shifts/{$this->shift()->id}/review")->assertSessionHasErrors('review_notes');
    }

    /** D-193: the Cash drawer settings were removed (future feature FE-05). */
    public function test_the_cash_drawer_settings_are_gone(): void
    {
        $this->actingAs($this->owner)->put('/admin/settings', ['name' => 'Brevon', 'cash_approval_over' => '100.00', 'shift_review_variance_over' => '20'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('settings', ['key' => 'cash.approval_over']);
        $this->assertDatabaseMissing('settings', ['key' => 'shift.review_variance_over']);
        $this->actingAs($this->owner)->get('/admin/settings')->assertInertia(fn ($page) => $page
            ->missing('settings.cash_approval_over')
            ->missing('settings.shift_review_variance_over'));
    }

    public function test_managers_close_forgotten_shifts_from_the_review_page(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->manager)->get("/admin/shifts/{$shift->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.close', true)->where('summary.expected_cash', '1000.00'));

        $this->close($this->manager, '1000.00')->assertSessionHasNoErrors()->assertRedirect("/admin/shifts/{$shift->id}");
        $this->assertSame($this->manager->id, $this->shift()->closed_by);
    }

    public function test_shift_review_pages_are_limited_to_reviewers_at_their_locations(): void
    {
        $shift = $this->shift();
        $this->actingAs($this->cashier)->get('/admin/shifts')->assertForbidden();
        $this->actingAs($this->cashier)->get("/admin/shifts/{$shift->id}")->assertForbidden();

        $popUp = Branch::factory()->popUp()->create(['code' => 'POP09']);
        $elsewhere = $this->staff(PermissionCatalog::MANAGER, $popUp);
        $this->actingAs($elsewhere)->get("/admin/shifts/{$shift->id}")->assertNotFound();
        $this->actingAs($elsewhere)->post("/shifts/{$shift->id}/cash-movements", ['movement_type' => 'cash_in', 'amount' => '5', 'reason' => 'Test'])->assertNotFound();

        $this->actingAs($this->manager)->get('/admin/shifts')->assertOk()
            ->assertInertia(fn ($page) => $page->has('shifts.data', 1)->where('shifts.data.0.number', $shift->shift_number));
    }

    private function openShiftFor(User $user): CashierShift
    {
        $terminal = Terminal::query()->create(['branch_id' => $this->mainLocation()->id, 'terminal_code' => 'POS-2', 'name' => 'Back counter', 'is_active' => true]);
        $this->actingAs($user)->post('/staff/shift', ['terminal_id' => $terminal->id, 'opening_cash' => '100.00'])->assertSessionHasNoErrors();

        return CashierShift::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
