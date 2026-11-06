<?php

namespace App\Domain\Reports;

use App\Domain\Shared\Money;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales reports (FR-088–FR-092, FR-095, FR-097, UC-009; D-156–D-158). Every
 * figure is summed from order lines, so totals, periods, products,
 * categories and cashiers always reconcile with each other. A sale counts
 * on its business date when it was paid or is waiting for its GCash check,
 * and was not cancelled; an order whose GCash never arrived is left out.
 * Reports only read; they never change anything.
 */
class SalesReport
{
    public function summary(ReportFilters $f): SalesFigures
    {
        return SalesFigures::fromRow($this->aggregate($this->lines($f))->first() ?? (object) []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byPeriod(ReportFilters $f): array
    {
        $expr = match ($f->groupBy) {
            'week' => 'DATE_SUB(o.business_date, INTERVAL WEEKDAY(o.business_date) DAY)',
            'month' => "DATE_FORMAT(o.business_date, '%Y-%m-01')",
            default => 'o.business_date',
        };

        return array_values($this->aggregate($this->lines($f))
            ->selectRaw("{$expr} as period")
            ->groupByRaw($expr)->orderByRaw($expr)
            ->get()
            ->map(fn (object $row) => ['period' => $this->periodLabel((string) $row->period, $f->groupBy), 'start' => substr((string) $row->period, 0, 10), ...SalesFigures::fromRow($row)->toArray()])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byProduct(ReportFilters $f): array
    {
        return array_values($this->aggregate($this->lines($f))
            ->selectRaw('oi.product_id, oi.variant_id, MAX(oi.product_name_snapshot) as name, MAX(oi.variant_name_snapshot) as variant, MAX(c.name) as category')
            ->groupBy('oi.product_id', 'oi.variant_id')
            ->orderByRaw('SUM(oi.line_total) - SUM(oi.discount_amount) - SUM(COALESCE(rf.refunded, 0)) DESC')
            ->get()
            ->map(fn (object $row) => ['name' => $row->name, 'variant' => $row->variant, 'category' => $row->category, ...SalesFigures::fromRow($row)->toArray()])
            ->all());
    }

    /**
     * Grouped by each product's category now (categories are not
     * snapshotted on the sale).
     *
     * @return list<array<string, mixed>>
     */
    public function byCategory(ReportFilters $f): array
    {
        return array_values($this->aggregate($this->lines($f))
            ->selectRaw("COALESCE(MAX(c.name), 'No category') as name")
            ->groupBy('p.category_id')
            ->orderByRaw('SUM(oi.line_total) - SUM(oi.discount_amount) - SUM(COALESCE(rf.refunded, 0)) DESC')
            ->get()
            ->map(fn (object $row) => ['name' => $row->name, ...SalesFigures::fromRow($row)->toArray()])
            ->all());
    }

    /**
     * FR-092: sales taken by each cashier (the person who rang up the order).
     *
     * @return list<array<string, mixed>>
     */
    public function byCashier(ReportFilters $f): array
    {
        return array_values($this->aggregate($this->lines($f))
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->selectRaw('o.created_by, MAX(u.name) as name')
            ->selectRaw('COUNT(DISTINCT o.cashier_shift_id) as shifts')
            ->groupBy('o.created_by')
            ->orderBy('name')
            ->get()
            ->map(fn (object $row) => ['name' => $row->name, 'shifts' => (int) $row->shifts, ...SalesFigures::fromRow($row)->toArray()])
            ->all());
    }

    /**
     * FR-091, SRS §9 collections: money received per method on the sales in
     * range (paid at checkout, so on the sale's business date), GCash still
     * waiting for its check, and money given back per method on refunds
     * processed in range (refund-date view). Not filtered by category.
     *
     * @return array{rows: list<array<string, mixed>>, total: array<string, string>}
     */
    public function collections(ReportFilters $f): array
    {
        $payments = DB::table('payments as pay')
            ->join('orders as o', 'o.id', '=', 'pay.order_id')
            ->whereIn('o.branch_id', $f->branchIds)
            ->whereBetween('o.business_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->when($f->cashierId, fn (Builder $q, int $id) => $q->where('o.created_by', $id))
            ->when($f->paymentMethodId, fn (Builder $q, int $id) => $q->where('pay.payment_method_id', $id))
            ->groupBy('pay.payment_method_id')
            ->selectRaw('pay.payment_method_id as method_id')
            ->selectRaw('SUM(CASE WHEN pay.status IN (?, ?, ?) THEN pay.amount ELSE 0 END) as received', array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::taken()))
            ->selectRaw('SUM(CASE WHEN pay.status IN (?, ?, ?) THEN 1 ELSE 0 END) as received_count', array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::taken()))
            ->selectRaw('SUM(CASE WHEN pay.status = ? THEN pay.amount ELSE 0 END) as waiting', [PaymentStatus::Pending->value])
            ->get()->keyBy('method_id');

        [$start, $end] = $f->utcRange();
        $refunds = DB::table('refunds as r')
            ->join('payments as pay', 'pay.id', '=', 'r.payment_id')
            ->whereIn('r.branch_id', $f->branchIds)
            ->where('r.kind', RefundKind::Refund->value)
            ->where('r.processed_at', '>=', $start)->where('r.processed_at', '<', $end)
            ->when($f->paymentMethodId, fn (Builder $q, int $id) => $q->where('pay.payment_method_id', $id))
            ->groupBy('pay.payment_method_id')
            ->selectRaw('pay.payment_method_id as method_id, SUM(r.amount) as given_back, COUNT(*) as refund_count')
            ->get()->keyBy('method_id');

        $money = fn (mixed $v) => Money::fromDecimal(is_numeric($v) ? (string) $v : '0');
        $rows = [];
        $totals = ['received' => Money::zero(), 'waiting' => Money::zero(), 'given_back' => Money::zero()];
        foreach (PaymentMethod::query()->orderBy('id')->get() as $method) {
            $p = $payments->get($method->id);
            $r = $refunds->get($method->id);
            if ($p === null && $r === null) {
                continue;
            }
            $received = $money($p->received ?? null);
            $waiting = $money($p->waiting ?? null);
            $givenBack = $money($r->given_back ?? null);
            $totals['received'] = $totals['received']->add($received);
            $totals['waiting'] = $totals['waiting']->add($waiting);
            $totals['given_back'] = $totals['given_back']->add($givenBack);
            $rows[] = [
                'method' => $method->name,
                'payments' => (int) ($p->received_count ?? 0),
                'received' => $received->toDecimal(),
                'waiting' => $waiting->toDecimal(),
                'refunds' => (int) ($r->refund_count ?? 0),
                'given_back' => $givenBack->toDecimal(),
                'net' => $received->subtract($givenBack)->toDecimal(),
            ];
        }

        return ['rows' => $rows, 'total' => [
            'received' => $totals['received']->toDecimal(),
            'waiting' => $totals['waiting']->toDecimal(),
            'given_back' => $totals['given_back']->toDecimal(),
            'net' => $totals['received']->subtract($totals['given_back'])->toDecimal(),
        ]];
    }

    /** The order lines a report covers, with refunds given back on each line. */
    private function lines(ReportFilters $f): Builder
    {
        $refunded = DB::table('refund_items as ri')
            ->join('refunds as r', 'r.id', '=', 'ri.refund_id')
            ->where('r.kind', RefundKind::Refund->value)
            ->groupBy('ri.order_item_id')
            ->selectRaw('ri.order_item_id, SUM(ri.amount) as refunded');

        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoinSub($refunded, 'rf', 'rf.order_item_id', '=', 'oi.id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('o.branch_id', $f->branchIds === [] ? [0] : $f->branchIds)
            ->whereBetween('o.business_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->where('o.payment_status', '!=', OrderPaymentStatus::Unpaid->value)
            ->when($f->cashierId, fn (Builder $q, int $id) => $q->where('o.created_by', $id))
            ->when($f->categoryId, fn (Builder $q, int $id) => $q->where('p.category_id', $id))
            ->when($f->paymentMethodId, fn (Builder $q, int $id) => $q->whereExists(
                fn (Builder $s) => $s->selectRaw('1')->from('payments as pm')->whereColumn('pm.order_id', 'o.id')->where('pm.payment_method_id', $id)
            ));
    }

    private function aggregate(Builder $lines): Builder
    {
        return $lines->selectRaw('SUM(oi.line_total) as gross, SUM(oi.discount_amount) as discounts, SUM(COALESCE(rf.refunded, 0)) as refunds, SUM(oi.tax_amount) as tax, COUNT(DISTINCT o.id) as orders, SUM(oi.quantity) as quantity');
    }

    private function periodLabel(string $period, string $groupBy): string
    {
        $date = CarbonImmutable::parse(substr($period, 0, 10));

        return match ($groupBy) {
            'week' => 'Week of '.$date->format('M j, Y'),
            'month' => $date->format('F Y'),
            default => $date->format('D, M j, Y'),
        };
    }
}
