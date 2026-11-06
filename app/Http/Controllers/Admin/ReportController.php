<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Dashboard;
use App\Domain\Reports\OperationsReports;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\ReportTable;
use App\Domain\Reports\SalesReport;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard and sales reports (FR-088–FR-092, FR-095–FR-097, UC-009;
 * D-156–D-159). Routes: dashboard needs dashboard.view; the sales report
 * reports.sales; its CSV export reports.sales and reports.export.
 */
class ReportController extends Controller
{
    public const SECTIONS = ['periods', 'products', 'categories', 'cashiers', 'payments'];

    public function __construct(
        private readonly ReportScope $scope,
        private readonly SalesReport $sales,
    ) {}

    public function dashboard(Request $request, Dashboard $dashboard): Response
    {
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user, 'today');

        return Inertia::render('admin/Dashboard', [
            'filters' => $filters->toQuery(),
            'options' => ['locations' => $this->locationOptions($user)],
            ...$dashboard->build($filters),
            'canSeeReports' => $user->can('reports.sales'),
        ]);
    }

    public function sales(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user);

        return Inertia::render('admin/reports/Sales', [
            'filters' => $filters->toQuery(),
            'options' => $this->options($user),
            'summary' => $this->sales->summary($filters)->toArray(),
            'periods' => $this->sales->byPeriod($filters),
            'products' => $this->sales->byProduct($filters),
            'categories' => $this->sales->byCategory($filters),
            'cashiers' => $this->sales->byCashier($filters),
            'payments' => $this->sales->collections($filters),
            'canExport' => $user->can('reports.export'),
        ]);
    }

    /** FR-096: one section of the sales report as CSV or XLSX, audited. */
    public function exportSales(Request $request, string $section, ReportExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $format = $this->format($request);
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user);

        $audit->record('report.exported', $user, null, ['report' => "sales.{$section}", 'format' => $format, ...$filters->toQuery()], actor: $user);

        return $exporter->download($this->salesTable($section, $filters), $format, sprintf('brevon-sales-%s-%s-to-%s', $section, $filters->from->toDateString(), $filters->to->toDateString()));
    }

    /** FR-093, FR-094: refunds and voids, discounts, cash differences, stock, production, waste. */
    public function operations(Request $request, string $report, OperationsReports $reports): Response
    {
        $definition = $this->definition($request, $report);
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user);
        $built = $reports->build($report, $filters);
        $options = $this->options($user);

        return Inertia::render('admin/reports/Operations', [
            'report' => ['key' => $report, 'title' => $definition['title'], 'filters' => $definition['filters']],
            'filters' => $filters->toQuery(),
            'options' => [
                'locations' => $options['locations'],
                'cashiers' => in_array('cashier', $definition['filters'], true) ? $options['cashiers'] : null,
                'payment_methods' => in_array('payment_method', $definition['filters'], true) ? $options['payment_methods'] : null,
            ],
            'cards' => $built['cards'],
            'notes' => $built['notes'],
            'tables' => array_map(fn (ReportTable $t) => $t->toArray(), $built['tables']),
            'canExport' => $user->can('reports.export'),
        ]);
    }

    public function exportOperations(Request $request, string $report, string $table, OperationsReports $reports, ReportExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        $this->definition($request, $report);
        abort_unless($this->user($request)->can('reports.export'), 403);
        $format = $this->format($request);
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user);
        $found = collect($reports->build($report, $filters)['tables'])->first(fn (ReportTable $t) => $t->key === $table);
        abort_if($found === null, 404);

        $audit->record('report.exported', $user, null, ['report' => "{$report}.{$table}", 'format' => $format, ...$filters->toQuery()], actor: $user);

        return $exporter->download($found, $format, sprintf('brevon-%s-%s-%s-to-%s', $report, $table, $filters->from->toDateString(), $filters->to->toDateString()));
    }

    /**
     * @return array{title: string, permission: string, filters: list<string>}
     */
    private function definition(Request $request, string $report): array
    {
        $definition = OperationsReports::REPORTS[$report] ?? null;
        abort_if($definition === null, 404);
        abort_unless($this->user($request)->can($definition['permission']), 403);

        return $definition;
    }

    private function format(Request $request): string
    {
        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        return (string) $format;
    }

    private function salesTable(string $section, ReportFilters $f): ReportTable
    {
        $col = fn (string $k, string $l, string $t = 'text') => ReportTable::col($k, $l, $t);
        $figures = [$col('gross', 'Gross sales', 'money'), $col('discounts', 'Discounts', 'money'), $col('refunds', 'Refunds', 'money'), $col('net', 'Net sales', 'money'), $col('tax', 'Tax', 'money'), $col('orders', 'Orders', 'int'), $col('average', 'Average order', 'money'), $col('quantity', 'Quantity', 'quantity')];

        return match ($section) {
            'periods' => new ReportTable($section, 'By period', [$col('period', 'Period'), $col('start', 'Starts', 'date'), ...$figures], $this->sales->byPeriod($f)),
            'products' => new ReportTable($section, 'By product', [$col('name', 'Product'), $col('variant', 'Size'), $col('category', 'Category'), ...$figures], $this->sales->byProduct($f)),
            'categories' => new ReportTable($section, 'By category', [$col('name', 'Category'), ...$figures], $this->sales->byCategory($f)),
            'cashiers' => new ReportTable($section, 'By cashier', [$col('name', 'Cashier'), $col('shifts', 'Shifts', 'int'), ...$figures], $this->sales->byCashier($f)),
            default => new ReportTable($section, 'By payment method', [$col('method', 'Payment method'), $col('payments', 'Payments', 'int'), $col('received', 'Received', 'money'), $col('waiting', 'Waiting for check', 'money'), $col('refunds', 'Refunds', 'int'), $col('given_back', 'Given back', 'money'), $col('net', 'Net collected', 'money')], $this->sales->collections($f)['rows']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function options(User $user): array
    {
        $locations = $this->scope->locations($user);

        return [
            'locations' => $this->locationOptions($user),
            'cashiers' => User::query()
                ->whereIn('id', Order::query()->whereIn('branch_id', $locations->pluck('id'))->select('created_by'))
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'payment_methods' => PaymentMethod::query()->orderBy('id')->get(['id', 'name'])->map(fn (PaymentMethod $m) => ['id' => $m->id, 'name' => $m->name])->all(),
        ];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function locationOptions(User $user): array
    {
        $today = BusinessClock::today();

        return array_values($this->scope->locations($user)->map(fn (Branch $b) => [
            'id' => $b->id,
            'label' => $b->name.($b->operatingStatus($today) === 'operating' ? '' : ' (closed)'),
        ])->all());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
