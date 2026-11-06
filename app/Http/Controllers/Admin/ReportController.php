<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Dashboard;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
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

    /** FR-096: one section of the sales report as CSV, audited. */
    public function exportSales(Request $request, string $section, AuditLogger $audit): StreamedResponse
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $user = $this->user($request);
        $filters = $this->scope->filters($request, $user);
        [$header, $rows] = $this->table($section, $filters);

        $audit->record('report.exported', $user, null, ['report' => "sales.{$section}", ...$filters->toQuery()], actor: $user);

        $name = sprintf('brevon-sales-%s-%s-to-%s.csv', $section, $filters->from->toDateString(), $filters->to->toDateString());

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and ñ correctly
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => self::safeCell($v), $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function table(string $section, ReportFilters $f): array
    {
        $figures = ['Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Tax', 'Orders', 'Average order', 'Quantity'];
        $values = fn (array $r) => [$r['gross'], $r['discounts'], $r['refunds'], $r['net'], $r['tax'], $r['orders'], $r['average'], $r['quantity']];

        return match ($section) {
            'periods' => [['Period', 'Starts', ...$figures], array_map(fn (array $r) => [$r['period'], $r['start'], ...$values($r)], $this->sales->byPeriod($f))],
            'products' => [['Product', 'Size', 'Category', ...$figures], array_map(fn (array $r) => [$r['name'], $r['variant'], $r['category'], ...$values($r)], $this->sales->byProduct($f))],
            'categories' => [['Category', ...$figures], array_map(fn (array $r) => [$r['name'], ...$values($r)], $this->sales->byCategory($f))],
            'cashiers' => [['Cashier', 'Shifts', ...$figures], array_map(fn (array $r) => [$r['name'], $r['shifts'], ...$values($r)], $this->sales->byCashier($f))],
            default => [['Payment method', 'Payments', 'Received', 'Waiting for check', 'Refunds', 'Given back', 'Net collected'], array_map(fn (array $r) => [$r['method'], $r['payments'], $r['received'], $r['waiting'], $r['refunds'], $r['given_back'], $r['net']], $this->sales->collections($f)['rows'])],
        };
    }

    /** Stops a spreadsheet from running a cell as a formula (CSV injection). */
    private static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
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
