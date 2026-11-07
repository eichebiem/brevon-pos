<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Locations\LocationService;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shifts\ExpectedCash;
use App\Domain\Shifts\ShiftService;
use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** Phase 10a: dashboard and sales reports (FR-088–FR-092, FR-095–FR-097, FR-105, FR-108; T-RPT-1..4; D-156–D-159). */
class SalesReportTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    private Product $choco;

    private Product $glazed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->manager = $this->staff(PermissionCatalog::MANAGER, null, ['username' => 'mgr', 'password' => 'secret-pass']);
        $this->cashier = $this->cashierOnShift();
        $this->choco = $this->doughnut('44.00', '20');
        $this->glazed = $this->doughnut('35.00', '20', name: 'Glazed');
    }

    private function sell(array $items, array $payment = ['method' => 'CASH', 'amount_tendered' => '1000'], array $extra = [], ?User $as = null): Order
    {
        $this->checkout($as ?? $this->cashier, $this->payload($items, $payment, $extra))->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * Today's fixture (T-RPT-1):
     *  A: 3 Choco + 1 Glazed less 12.5% = 146.12 cash, one Choco refunded (38.50)
     *  B: 2 Glazed = 70.00 cash
     *  C: 1 Choco = 44.00 GCash waiting for its check
     *  left out: a cancelled coffee and a GCash order marked not received.
     */
    private function fixture(): Order
    {
        $promo = new Discount;
        $promo->forceFill(['name' => 'Promo', 'kind' => DiscountKind::Standard, 'discount_type' => DiscountType::Percentage, 'value' => '12.50', 'scope' => DiscountScope::Order, 'is_active' => true])->save();
        $a = $this->sell([['product_id' => $this->choco->id, 'quantity' => 3], ['product_id' => $this->glazed->id, 'quantity' => 1]], extra: ['discount' => ['id' => $promo->id]]);
        $chocoLine = OrderItem::query()->where('order_id', $a->id)->where('product_id', $this->choco->id)->sole();
        $this->actingAs($this->cashier)->post("/orders/{$a->id}/refunds", [
            'lines' => [['order_item_id' => $chocoLine->id, 'quantity' => '1', 'return_to_stock' => false]],
            'reason' => 'Dropped', 'seen_refunded' => '0.00', 'approver_username' => 'mgr', 'approver_password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        $this->sell([['product_id' => $this->glazed->id, 'quantity' => 2]]);
        $this->sell([['product_id' => $this->choco->id, 'quantity' => 1]], ['method' => 'GCASH', 'reference' => 'GC11112222']);

        $coffee = $this->coffee();
        $cancelled = $this->sell([['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 1]]);
        $this->pastCancellation($cancelled, $this->manager, 'Out of milk');

        return $a;
    }

    public function test_figures_follow_the_definitions_and_reconcile_with_the_orders(): void
    {
        $this->fixture();

        $this->actingAs($this->manager)->get('/admin/reports/sales')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/reports/Sales')
                ->where('summary', ['gross' => '281.00', 'discounts' => '20.88', 'refunds' => '38.50', 'net' => '221.62', 'orders' => 3, 'average' => '73.87', 'quantity' => '7'])
                ->has('periods', 1)
                ->where('periods.0.net', '221.62')
                ->has('products', 2)
                ->where('products.0.name', 'Choco Custard')
                ->where('products.0.gross', '176.00')
                ->where('products.0.discounts', '16.50')
                ->where('products.0.refunds', '38.50')
                ->where('products.0.net', '121.00')
                ->where('products.1.net', '100.62')
                ->has('categories', 2)
                ->where('categories.0.net', '121.00')
                ->where('categories.1.net', '100.62')
                ->where('cashiers.0.name', $this->cashier->name)
                ->where('cashiers.0.orders', 3)
                ->where('cashiers.0.shifts', 1)
                ->where('payments.rows.0.method', 'Cash')
                ->where('payments.rows.0.received', '216.12')
                ->where('payments.rows.0.given_back', '38.50')
                ->where('payments.rows.0.net', '177.62')
                ->where('payments.rows.1.method', 'GCash')
                ->where('payments.rows.1.received', '44.00')
                ->where('payments.total.net', '221.62'));

        // Net collected in cash equals what the cashier's drawer took in sales less refunds.
        $c = app(ExpectedCash::class)->components($this->shiftOf($this->cashier));
        $this->assertSame('177.62', $c['cash_sales']->subtract($c['refunds'])->toDecimal());

        // Filters: a category, a payment method, a cashier.
        $this->actingAs($this->manager)->get('/admin/reports/sales?payment_method='.Payment::query()->where('reference_number', 'GC11112222')->sole()->payment_method_id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '44.00')->where('summary.orders', 1));
        $this->actingAs($this->manager)->get('/admin/reports/sales?cashier='.$this->manager->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.orders', 0)->where('summary.net', '0.00'));
        $this->actingAs($this->manager)->get('/admin/reports/sales?category='.$this->glazed->category_id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.orders', 2)->where('summary.net', '100.62'));

        // The dashboard shows the same figures for today.
        $this->actingAs($this->owner)->get('/admin/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Dashboard')
                ->where('figures.net', '221.62')
                ->where('figures.orders', 3)
                ->where('figures.average', '73.87')
                ->has('days', 1)
                ->where('top_products.0.name', 'Choco Custard')
                ->where('low_stock', 0));
    }

    public function test_dates_follow_manila_business_days_and_locations_are_kept_apart(): void
    {
        // T-RPT-2, FR-095. Dates are relative to today's Manila business date.
        $today = BusinessClock::today();
        [$d0, $d1] = [$today->toDateString(), $today->subDay()->toDateString()];
        $yesterday = $this->sell([['product_id' => $this->choco->id, 'quantity' => 1]]);
        DB::table('orders')->where('id', $yesterday->id)->update(['business_date' => $d1]);
        $this->sell([['product_id' => $this->glazed->id, 'quantity' => 1]]);

        $this->actingAs($this->manager)->get("/admin/reports/sales?from={$d1}&to={$d1}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '44.00')->where('filters.from', $d1));
        $months = $today->month === $today->subDay()->month ? 1 : 2;
        $this->actingAs($this->manager)->get("/admin/reports/sales?from={$d1}&to={$d0}&group=month")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '79.00')->has('periods', $months)->where('periods.'.($months - 1).'.period', $today->format('F Y')));
        $this->actingAs($this->manager)->get("/admin/reports/sales?from={$d0}&to={$d1}")->assertSessionHasErrors('to');

        // Refunds given back count on the Manila day they were processed.
        $f = new ReportFilters(CarbonImmutable::parse('2026-11-06'), CarbonImmutable::parse('2026-11-06'), [1]);
        [$start, $end] = $f->utcRange();
        $this->assertSame(['2026-11-05 16:00:00', '2026-11-06 16:00:00'], [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

        // A pop-up's sales stay at the pop-up.
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP01', 'name' => 'Mall Kiosk']);
        $kioskDoughnut = $this->doughnut('50.00', '5', $popUp, 'Kiosk Glazed');
        $kioskCashier = $this->cashierOnShift($popUp);
        $this->sell([['product_id' => $kioskDoughnut->id, 'quantity' => 2]], as: $kioskCashier);

        $main = $this->mainLocation();
        $this->actingAs($this->owner)->get("/admin/reports/sales?from={$d1}&location={$main->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '79.00'));
        $this->actingAs($this->owner)->get("/admin/reports/sales?from={$d1}&location={$popUp->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '100.00'));
        $this->actingAs($this->owner)->get("/admin/reports/sales?from={$d1}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '179.00'));

        // The manager is assigned only to the main branch: "all" means main, and the pop-up is refused.
        $this->actingAs($this->manager)->get("/admin/reports/sales?from={$d1}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.net', '79.00')->has('options.locations', 1));
        $this->actingAs($this->manager)->get("/admin/reports/sales?location={$popUp->id}")->assertSessionHasErrors('location');
    }

    public function test_reports_and_exports_need_their_permissions_and_exports_are_safe_and_logged(): void
    {
        // T-RPT-3, FR-096.
        $prep = $this->staff($this->bakerRole());
        foreach ([$this->cashier, $prep] as $user) {
            $this->actingAs($user)->get('/admin/reports/sales')->assertForbidden();
            $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
            $this->actingAs($user)->get('/admin/reports/sales/export/products')->assertForbidden();
        }

        $viewer = $this->staff(PermissionCatalog::CASHIER);
        $viewer->givePermissionTo('reports.sales');
        $this->actingAs($viewer)->get('/admin/reports/sales')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canExport', false));
        $this->actingAs($viewer)->get('/admin/reports/sales/export/products')->assertForbidden();

        $tricky = $this->doughnut('40.00', '5', name: '=HYPERLINK("http://x")');
        $this->sell([['product_id' => $tricky->id, 'quantity' => 1], ['product_id' => $this->choco->id, 'quantity' => 2]]);

        $response = $this->actingAs($this->manager)->get('/admin/reports/sales/export/products');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame('Product,Size,Category,"Gross sales",Discounts,Refunds,"Net sales",Orders,"Average order",Quantity', $lines[0]);
        $this->assertStringStartsWith('"Choco Custard",,', $lines[1]);
        $this->assertStringContainsString('88.00', $lines[1]);
        $this->assertStringStartsWith("\"'=HYPERLINK", $lines[2]);

        $this->actingAs($this->manager)->get('/admin/reports/sales/export/nonsense')->assertNotFound();
        $log = AuditLog::query()->where('event', 'report.exported')->sole();
        $this->assertSame('sales.products', $log->new_values['report']);
    }

    public function test_a_closed_pop_ups_history_stays_in_the_reports(): void
    {
        // T-RPT-4, FR-105, FR-108.
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP01', 'name' => 'Mall Kiosk']);
        $kioskDoughnut = $this->doughnut('50.00', '5', $popUp, 'Kiosk Glazed');
        $kioskCashier = $this->cashierOnShift($popUp);
        $this->sell([['product_id' => $kioskDoughnut->id, 'quantity' => 1]], as: $kioskCashier);
        $shift = $this->shiftOf($kioskCashier);
        app(ShiftService::class)->close($kioskCashier, $shift, app(ExpectedCash::class)->for($shift), null);
        app(LocationService::class)->deactivate($this->owner, $popUp, 'Season over');

        $this->actingAs($this->owner)->get("/admin/reports/sales?location={$popUp->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.net', '50.00')
                ->where('products.0.name', 'Kiosk Glazed')
                ->where('options.locations', fn ($locations) => collect($locations)->contains('label', 'Mall Kiosk (closed)')));
        $this->actingAs($this->owner)->get("/admin/dashboard?location={$popUp->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('figures.net', '50.00'));
    }

    public function test_the_dashboard_counts_low_stock_at_the_chosen_locations(): void
    {
        $item = InventoryItem::query()->findOrFail($this->choco->inventory_item_id);
        $item->forceFill(['reorder_level' => '25'])->save();

        $this->actingAs($this->owner)->get('/admin/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('low_stock', 1)->where('figures.orders', 0)->where('days', []));
    }
}
