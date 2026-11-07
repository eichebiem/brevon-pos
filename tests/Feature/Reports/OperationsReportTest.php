<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockWaste;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shifts\CashDrawer;
use App\Domain\Shifts\ExpectedCash;
use App\Domain\Shifts\ShiftService;
use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryMovementType;
use App\Models\AuditLog;
use App\Models\Discount;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;
use ZipArchive;

/** Phase 10b: operational reports (FR-093, FR-094, FR-095, FR-096; T-RPT-1..3; D-160–D-163). */
class OperationsReportTest extends TestCase
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
        $this->choco = $this->doughnut('44.00', '10');
        $this->glazed = $this->doughnut('35.00', '10', name: 'Glazed');
    }

    /** 3 Choco + 1 Glazed less 12.5% = 146.12 cash, one Choco refunded (38.50); a coffee cancelled (110.00). */
    private function fixture(): Order
    {
        $promo = new Discount;
        $promo->forceFill(['name' => 'Promo', 'kind' => DiscountKind::Standard, 'discount_type' => DiscountType::Percentage, 'value' => '12.50', 'scope' => DiscountScope::Order, 'is_active' => true])->save();
        $this->checkout($this->cashier, $this->payload([['product_id' => $this->choco->id, 'quantity' => 3], ['product_id' => $this->glazed->id, 'quantity' => 1]], extra: ['discount' => ['id' => $promo->id]]))->assertSessionHasNoErrors();
        $sale = Order::query()->latest('id')->firstOrFail();
        $line = OrderItem::query()->where('order_id', $sale->id)->where('product_id', $this->choco->id)->sole();
        $this->actingAs($this->cashier)->post("/orders/{$sale->id}/refunds", [
            'lines' => [['order_item_id' => $line->id, 'quantity' => '1', 'return_to_stock' => true]],
            'reason' => 'Dropped', 'seen_refunded' => '0.00', 'approver_username' => 'mgr', 'approver_password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        $coffee = $this->coffee();
        $this->checkout($this->cashier, $this->payload([['product_id' => $coffee['product']->id, 'variant_id' => $coffee['iced']->id, 'quantity' => 1]]))->assertSessionHasNoErrors();
        $cancelled = Order::query()->latest('id')->firstOrFail();
        $this->pastCancellation($cancelled, $this->manager, 'Out of milk');

        return $sale;
    }

    public function test_refunds_and_voids_are_listed_on_the_day_the_money_went_back(): void
    {
        $sale = $this->fixture();

        $this->actingAs($this->manager)->get('/admin/reports/refunds')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/reports/Operations')
                ->where('report.title', 'Refunds and voids')
                ->where('cards.0.value', '38.50')
                ->where('cards.1.value', '110.00')
                ->where('cards.2.value', '148.50')
                ->has('tables.0.rows', 2)
                ->where('tables.0.rows.0.kind', 'Refund')
                ->where('tables.0.rows.0.order_number', $sale->order_number)
                ->where('tables.0.rows.0.approved_by', $this->manager->name)
                ->where('tables.0.rows.1.kind', 'Void')
                ->where('tables.0.totals.amount', '148.50')
                ->where('options.cashiers', fn ($c) => count($c) > 0));

        // Refund-date view: the same refund moved two days back leaves today's list.
        DB::table('refunds')->where('kind', 'refund')->update(['processed_at' => now()->subDays(2)]);
        $today = now('Asia/Manila')->toDateString();
        $this->actingAs($this->manager)->get("/admin/reports/refunds?from={$today}&to={$today}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.value', '0.00')->has('tables.0.rows', 1));
    }

    public function test_discounts_match_the_sales_report(): void
    {
        $this->fixture();

        $this->actingAs($this->manager)->get('/admin/reports/discounts')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.value', '20.88')
                ->where('cards.1.value', '12.50') // 20.88 of 167.00 gross
                ->where('tables.0.rows.0.name', 'Promo')
                ->where('tables.0.rows.0.uses', 1)
                ->where('tables.0.rows.0.kind', 'Preset')
                ->where('tables.1.rows.0.applied_by', $this->cashier->name));
        $this->actingAs($this->manager)->get('/admin/reports/sales')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.discounts', '20.88'));
    }

    public function test_cash_differences_include_corrections_made_after_closing(): void
    {
        $this->fixture();
        $shift = $this->shiftOf($this->cashier);
        $expected = app(ExpectedCash::class)->for($shift); // 1000 + 146.12 − 38.50
        $this->assertSame('1107.62', $expected->toDecimal());
        app(ShiftService::class)->close($this->cashier, $shift, $expected->subtract(Money::fromDecimal('10.00')), 'Counted twice');
        app(CashDrawer::class)->correct($this->manager, $shift->refresh(), 'counted', Money::fromDecimal('4.00'), 'Found ₱4 in the tray');

        $this->actingAs($this->manager)->get('/admin/reports/cash-variance')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.value', '1')
                ->where('cards.1.value', '-6.00')
                ->where('cards.3.value', '-6.00')
                ->where('tables.0.rows.0.expected', '1107.62')
                ->where('tables.0.rows.0.variance', '-10.00')
                ->where('tables.0.rows.0.corrections', '4.00')
                ->where('tables.0.rows.0.corrected', '-6.00')
                ->where('tables.0.rows.0.review', 'Needs review'));
    }

    public function test_stock_movements_add_up_from_start_to_end(): void
    {
        $this->fixture(); // 10 Choco opened, 3 sold, 1 returned

        $this->actingAs($this->manager)->get('/admin/reports/stock-movements')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tables.0.rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['item'] === $this->choco->inventoryItem->name
                    && $r['start'] === '0.000' && $r['received'] === '10.000' && $r['sold'] === '-3.000' && $r['returned'] === '1.000' && $r['end'] === '8.000')));

        // Yesterday the stock did not exist yet: nothing to show.
        $yesterday = now('Asia/Manila')->subDay()->toDateString();
        $this->actingAs($this->manager)->get("/admin/reports/stock-movements?from={$yesterday}&to={$yesterday}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tables.0.rows', 0));
    }

    public function test_production_and_waste_reports(): void
    {
        $doughnut = Product::factory()->finishedStock()->create(['name' => 'Choco Custard Batch']);
        $flour = $this->ingredientItem('ING-FLOUR');
        $pc = UnitOfMeasure::query()->firstOrCreate(['code' => 'pc'], ['name' => 'Piece', 'precision' => 0]);
        $egg = InventoryItem::query()->create(['sku' => 'ING-EGG', 'name' => 'Egg', 'item_type' => InventoryItemType::Ingredient, 'unit_of_measure_id' => $pc->id]);
        $main = $this->mainLocation();
        app(InventoryLedger::class)->post($this->owner, [
            new StockChange($main->id, $flour->id, InventoryMovementType::Opening, Quantity::fromString('5000'), '0.0500'),
            new StockChange($main->id, $egg->id, InventoryMovementType::Opening, Quantity::fromString('30'), '9.0000'),
        ]);
        $this->actingAs($this->owner)->post("/admin/recipes/{$doughnut->id}", [
            'output_quantity' => '12',
            'lines' => [['inventory_item_id' => $flour->id, 'quantity' => '1000', 'wastage_percent' => '5'], ['inventory_item_id' => $egg->id, 'quantity' => '4', 'wastage_percent' => '']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/admin/production', ['product_id' => $doughnut->id, 'planned' => '12', 'actual' => '10', 'rejected' => '2', 'reject_reason' => 'Burnt', 'post' => true])->assertSessionHasNoErrors();

        // Flour 1050 g × ₱0.05 + 4 eggs × ₱9 = ₱88.50 for 10 good pieces.
        $this->actingAs($this->manager)->get('/admin/reports/production')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.value', '1')
                ->where('cards.1.value', '10.000')
                ->where('cards.2.value', '2.000')
                ->where('cards.2.note', '16.66% of pieces made')
                ->where('tables.0.rows.0.product', 'Choco Custard Batch')
                ->where('tables.0.rows.0.cost', '88.50')
                ->where('tables.0.rows.0.per_piece', '8.85')
                ->where('tables.1.rows.0.reject_reason', 'Burnt'));

        app(StockWaste::class)->record($this->manager, $main, 'Spilled', [['inventory_item_id' => $flour->id, 'quantity' => '200']]);
        $this->actingAs($this->manager)->get('/admin/reports/waste')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.value', '1')
                ->where('cards.1.value', '10.00') // 200 g × ₱0.05
                ->where('cards.2.value', '2.000')
                ->where('tables.0.rows.0.quantity', '200.000')
                ->where('tables.1.rows.0.reason', 'Spilled'));
    }

    public function test_permissions_and_exports(): void
    {
        $this->fixture();
        $prep = $this->staff($this->bakerRole());
        foreach (['refunds', 'discounts', 'cash-variance', 'stock-movements', 'production', 'waste'] as $report) {
            $this->actingAs($this->cashier)->get("/admin/reports/{$report}")->assertForbidden();
            $this->actingAs($prep)->get("/admin/reports/{$report}")->assertForbidden();
            $this->actingAs($this->manager)->get("/admin/reports/{$report}")->assertOk();
        }
        $this->actingAs($this->manager)->get('/admin/reports/nonsense')->assertNotFound();

        // Inventory reports need reports.inventory, sales-side ones reports.sales.
        $stockOnly = $this->staff(PermissionCatalog::CASHIER);
        $stockOnly->givePermissionTo('reports.inventory');
        $this->actingAs($stockOnly)->get('/admin/reports/waste')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canExport', false));
        $this->actingAs($stockOnly)->get('/admin/reports/refunds')->assertForbidden();
        $this->actingAs($stockOnly)->get('/admin/reports/waste/export/by-item')->assertForbidden();

        // CSV and XLSX.
        $csv = $this->actingAs($this->manager)->get('/admin/reports/refunds/export/list')->assertOk()->streamedContent();
        $this->assertStringContainsString('When,Number,Type,Order', $csv);
        $this->assertStringContainsString('Total', $csv);
        $this->actingAs($this->manager)->get('/admin/reports/refunds/export/missing')->assertNotFound();
        $this->actingAs($this->manager)->get('/admin/reports/refunds/export/list?format=pdf')->assertNotFound();

        DB::table('refunds')->where('kind', 'void')->update(['reason' => '=HYPERLINK("http://x")']);
        $response = $this->actingAs($this->manager)->get('/admin/reports/refunds/export/list?format=xlsx');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('=HYPERLINK', html_entity_decode($sheet));
        $this->assertStringNotContainsString('<f>', $sheet, 'text is never written as a formula');
        $this->assertStringContainsString('<v>38.5</v>', $sheet, 'amounts are numbers');

        $sales = $this->actingAs($this->manager)->get('/admin/reports/sales/export/products?format=xlsx');
        $this->assertStringContainsString('spreadsheetml', (string) $sales->headers->get('Content-Type'));

        $this->assertSame(['refunds.list', 'refunds.list', 'sales.products'], AuditLog::query()->where('event', 'report.exported')->orderBy('id')->get()->map(fn (AuditLog $l) => $l->new_values['report'])->all());
    }
}
