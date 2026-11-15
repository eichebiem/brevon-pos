<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shifts\ShiftSummary;
use App\Enums\DiscountKind;
use App\Enums\InventoryMovementType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundKind;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports (FR-093, FR-094, FR-095; D-160–D-163): refunds and
 * voids, discounts, cash differences, stock movements and waste.
 * Each returns summary cards and tables in one shape, so screens and
 * exports share it. Reports only read.
 */
class OperationsReports
{
    /**
     * key => [title, permission, filters shown besides dates and location]
     *
     * @var array<string, array{title: string, permission: string, filters: list<string>}>
     */
    public const REPORTS = [
        'refunds' => ['title' => 'Refunds and voids', 'permission' => 'reports.sales', 'filters' => ['cashier', 'payment_method']],
        'discounts' => ['title' => 'Discounts', 'permission' => 'reports.sales', 'filters' => ['cashier']],
        'cash-variance' => ['title' => 'Cash differences', 'permission' => 'reports.sales', 'filters' => ['cashier']],
        'stock-movements' => ['title' => 'Stock movements', 'permission' => 'reports.inventory', 'filters' => []],
        'waste' => ['title' => 'Waste', 'permission' => 'reports.inventory', 'filters' => []],
    ];

    public function __construct(
        private readonly SalesReport $sales,
        private readonly ShiftSummary $shifts,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    public function build(string $report, ReportFilters $f): array
    {
        return match ($report) {
            'refunds' => $this->refunds($f),
            'discounts' => $this->discounts($f),
            'cash-variance' => $this->cashVariance($f),
            'stock-movements' => $this->stockMovements($f),
            default => $this->waste($f),
        };
    }

    /**
     * Refund-date view (SRS §9): refunds and voids processed in these dates.
     *
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    private function refunds(ReportFilters $f): array
    {
        [$start, $end] = $f->utcRange();
        $rows = DB::table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->leftJoin('payments as pay', 'pay.id', '=', 'r.payment_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'pay.payment_method_id')
            ->leftJoin('users as req', 'req.id', '=', 'r.requested_by')
            ->leftJoin('users as app', 'app.id', '=', 'r.approved_by')
            ->whereIn('r.branch_id', $this->branches($f))
            ->where('r.processed_at', '>=', $start)->where('r.processed_at', '<', $end)
            ->when($f->cashierId, fn (Builder $q, int $id) => $q->where('r.requested_by', $id))
            ->when($f->paymentMethodId, fn (Builder $q, int $id) => $q->where('pay.payment_method_id', $id))
            ->orderBy('r.processed_at')->orderBy('r.id')
            ->get(['r.processed_at', 'r.refund_number', 'r.kind', 'o.order_number', 'o.business_date', 'b.code as location', 'pm.name as method', 'r.amount', 'r.reference_number', 'r.reason', 'req.name as by', 'app.name as approved_by']);

        $sum = fn (string $kind) => Money::sum($rows->where('kind', $kind)->map(fn (object $r) => Money::fromDecimal((string) $r->amount)));
        $refunded = $sum(RefundKind::Refund->value);
        $voided = $sum(RefundKind::Void->value);

        return [
            'cards' => [
                ['label' => 'Refunds', 'value' => $refunded->toDecimal(), 'type' => 'money', 'note' => $rows->where('kind', RefundKind::Refund->value)->count().' refunds of completed sales'],
                ['label' => 'Voids', 'value' => $voided->toDecimal(), 'type' => 'money', 'note' => $rows->where('kind', RefundKind::Void->value)->count().' cancelled orders handed back'],
                ['label' => 'Total given back', 'value' => $refunded->add($voided)->toDecimal(), 'type' => 'money'],
            ],
            'notes' => [
                'Listed on the day the money was given back (Manila time). The sales report counts refunds against the day of the sale instead.',
                'Voids are cancellations of orders that were still being made; their payment never counts as a sale.',
            ],
            'tables' => [new ReportTable('list', 'Refunds and voids', [
                ReportTable::col('processed_at', 'When', 'datetime'),
                ReportTable::col('refund_number', 'Number'),
                ReportTable::col('kind', 'Type'),
                ReportTable::col('order_number', 'Order'),
                ReportTable::col('sale_date', 'Sale date', 'date'),
                ReportTable::col('location', 'Location'),
                ReportTable::col('method', 'Method'),
                ReportTable::col('reference_number', 'Reference'),
                ReportTable::col('amount', 'Amount', 'money'),
                ReportTable::col('reason', 'Reason'),
                ReportTable::col('by', 'By'),
                ReportTable::col('approved_by', 'Approved by'),
            ], array_values($rows->map(fn (object $r) => [
                'processed_at' => $this->iso($r->processed_at),
                'refund_number' => $r->refund_number,
                'kind' => RefundKind::from((string) $r->kind)->label(),
                'order_number' => $r->order_number,
                'sale_date' => substr((string) $r->business_date, 0, 10),
                'location' => $r->location,
                'method' => $r->method,
                'reference_number' => $r->reference_number,
                'amount' => (string) $r->amount,
                'reason' => $r->reason,
                'by' => $r->by,
                'approved_by' => $r->approved_by,
            ])->all()), ['processed_at' => null, 'refund_number' => 'Total', 'amount' => $refunded->add($voided)->toDecimal()], 'No refunds or voids in these dates.')],
        ];
    }

    /**
     * Discounts on the sales in range, counted like the sales report (D-156).
     *
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    private function discounts(ReportFilters $f): array
    {
        $base = fn () => DB::table('order_discounts as od')
            ->join('orders as o', 'o.id', '=', 'od.order_id')
            ->leftJoin('discounts as d', 'd.id', '=', 'od.discount_id')
            ->whereIn('o.branch_id', $this->branches($f))
            ->whereBetween('o.business_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->where('o.payment_status', '!=', OrderPaymentStatus::Unpaid->value)
            ->when($f->cashierId, fn (Builder $q, int $id) => $q->where('o.created_by', $id));

        $byDiscount = $base()
            ->groupBy('od.discount_id')
            ->selectRaw('MAX(od.discount_name_snapshot) as name, MAX(d.kind) as kind, COUNT(*) as uses, SUM(od.discount_amount) as amount, SUM(CASE WHEN od.approved_by IS NULL THEN 0 ELSE 1 END) as approved')
            ->orderByRaw('SUM(od.discount_amount) DESC')
            ->get();
        $list = $base()
            ->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->leftJoin('users as ap', 'ap.id', '=', 'od.applied_by')
            ->leftJoin('users as mg', 'mg.id', '=', 'od.approved_by')
            ->orderBy('o.business_date')->orderBy('o.id')
            ->get(['o.business_date', 'o.order_number', 'b.code as location', 'od.discount_name_snapshot as name', 'o.subtotal', 'od.discount_amount', 'od.reason', 'ap.name as applied_by', 'mg.name as approved_by']);

        $total = Money::sum($byDiscount->map(fn (object $r) => Money::fromDecimal((string) $r->amount)));
        $gross = $this->sales->summary(new ReportFilters($f->from, $f->to, $f->branchIds, $f->cashierId))->gross;
        $share = $gross->isZero() ? '0.00' : bcdiv(bcmul((string) $total->minor(), '100', 4), (string) $gross->minor(), 2);

        return [
            'cards' => [
                ['label' => 'Discounts given', 'value' => $total->toDecimal(), 'type' => 'money', 'note' => $list->count().' orders'],
                ['label' => 'Share of gross sales', 'value' => $share, 'type' => 'percent'],
                ['label' => 'Approved by a manager', 'value' => (string) $list->whereNotNull('approved_by')->count(), 'type' => 'int'],
            ],
            'notes' => [
                'Counted on the day of the sale, for sales that were paid and not cancelled — the same as the sales report.',
                'Senior Citizen / PWD details are never shown in reports.',
            ],
            'tables' => [
                new ReportTable('by-discount', 'By discount', [
                    ReportTable::col('name', 'Discount'),
                    ReportTable::col('kind', 'Kind'),
                    ReportTable::col('uses', 'Orders', 'int'),
                    ReportTable::col('approved', 'Approved', 'int'),
                    ReportTable::col('amount', 'Amount', 'money'),
                ], array_values($byDiscount->map(fn (object $r) => [
                    'name' => $r->name,
                    'kind' => $r->kind === DiscountKind::SeniorPwd->value ? 'Senior Citizen / PWD' : 'Preset',
                    'uses' => (int) $r->uses,
                    'approved' => (int) $r->approved,
                    'amount' => (string) $r->amount,
                ])->all()), ['name' => 'Total', 'uses' => $list->count(), 'amount' => $total->toDecimal()], 'No discounts in these dates.'),
                new ReportTable('orders', 'Discounted orders', [
                    ReportTable::col('date', 'Sale date', 'date'),
                    ReportTable::col('order_number', 'Order'),
                    ReportTable::col('location', 'Location'),
                    ReportTable::col('name', 'Discount'),
                    ReportTable::col('subtotal', 'Before discount', 'money'),
                    ReportTable::col('amount', 'Discount', 'money'),
                    ReportTable::col('applied_by', 'Given by'),
                    ReportTable::col('approved_by', 'Approved by'),
                    ReportTable::col('reason', 'Reason'),
                ], array_values($list->map(fn (object $r) => [
                    'date' => substr((string) $r->business_date, 0, 10),
                    'order_number' => $r->order_number,
                    'location' => $r->location,
                    'name' => $r->name,
                    'subtotal' => (string) $r->subtotal,
                    'amount' => (string) $r->discount_amount,
                    'applied_by' => $r->applied_by,
                    'approved_by' => $r->approved_by,
                    'reason' => $r->reason,
                ])->all()), null, 'No discounts in these dates.'),
            ],
        ];
    }

    /**
     * Closed shifts in range: the difference at closing and after
     * corrections (D-129, D-130). Open shifts have no difference yet.
     *
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    private function cashVariance(ReportFilters $f): array
    {
        $shifts = CashierShift::query()
            ->whereIn('branch_id', $this->branches($f))
            ->where('status', ShiftStatus::Closed)
            ->whereBetween('business_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->when($f->cashierId, fn ($q, int $id) => $q->where('user_id', $id))
            ->with(['cashier:id,name', 'branch:id,code', 'reviewer:id,name'])
            ->orderBy('business_date')->orderBy('id')
            ->get();

        $short = Money::zero();
        $over = Money::zero();
        $rows = [];
        $needsReview = 0;
        foreach ($shifts as $s) {
            $corrections = $this->shifts->corrections($s);
            $variance = $this->shifts->correctedVariance($s);
            $variance->isNegative() ? $short = $short->add($variance) : $over = $over->add($variance);
            $flag = $this->shifts->needsReview($s);
            $needsReview += $flag ? 1 : 0;
            $rows[] = [
                'date' => $s->business_date->toDateString(),
                'shift_number' => $s->shift_number,
                'location' => $s->branch?->code,
                'cashier' => $s->cashier?->name,
                'expected' => $s->expected_cash,
                'counted' => $s->counted_cash,
                'variance' => $s->variance,
                'corrections' => $corrections['counted']->subtract($corrections['expected'])->toDecimal(),
                'corrected' => $variance->toDecimal(),
                'review' => $s->reviewed_at !== null ? 'Reviewed by '.($s->reviewer->name ?? '—') : ($flag ? 'Needs review' : 'Not reviewed'),
            ];
        }

        return [
            'cards' => [
                ['label' => 'Closed shifts', 'value' => (string) count($rows), 'type' => 'int', 'note' => $needsReview.' need review'],
                ['label' => 'Short', 'value' => $short->toDecimal(), 'type' => 'money'],
                ['label' => 'Over', 'value' => $over->toDecimal(), 'type' => 'money'],
                ['label' => 'Net difference', 'value' => $short->add($over)->toDecimal(), 'type' => 'money'],
            ],
            'notes' => ['Difference = cash counted − cash expected. After corrections includes corrections added by a manager after closing. Shifts still open are not listed.'],
            'tables' => [new ReportTable('shifts', 'Closed shifts', [
                ReportTable::col('date', 'Date', 'date'),
                ReportTable::col('shift_number', 'Shift'),
                ReportTable::col('location', 'Location'),
                ReportTable::col('cashier', 'Cashier'),
                ReportTable::col('expected', 'Expected', 'money'),
                ReportTable::col('counted', 'Counted', 'money'),
                ReportTable::col('variance', 'Difference', 'money'),
                ReportTable::col('corrections', 'Corrections', 'money'),
                ReportTable::col('corrected', 'After corrections', 'money'),
                ReportTable::col('review', 'Review'),
            ], $rows, ['date' => null, 'shift_number' => 'Total', 'corrected' => $short->add($over)->toDecimal()], 'No closed shifts in these dates.')],
        ];
    }

    /**
     * Stock in and out per item in range (FR-093): start and end balances
     * come from the ledger's running balances.
     *
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    private function stockMovements(ReportFilters $f): array
    {
        [$start, $end] = $f->utcRange();
        $branches = $this->branches($f);

        $moved = DB::table('inventory_movements as m')
            ->whereIn('m.branch_id', $branches)
            ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end)
            ->groupBy('m.inventory_item_id', 'm.movement_type')
            ->selectRaw('m.inventory_item_id, m.movement_type, SUM(m.quantity_delta) as qty, COUNT(*) as n')
            ->get();

        $balanceAt = fn ($before) => DB::table('inventory_movements as m')
            ->whereIn('m.id', DB::table('inventory_movements')->whereIn('branch_id', $branches)->where('created_at', '<', $before)->groupBy('branch_id', 'inventory_item_id')->selectRaw('MAX(id)'))
            ->groupBy('m.inventory_item_id')
            ->selectRaw('m.inventory_item_id, SUM(m.balance_after) as qty')
            ->pluck('qty', 'inventory_item_id');
        $opening = $balanceAt($start);
        $closing = $balanceAt($end);

        $itemIds = $moved->pluck('inventory_item_id')->merge($opening->keys())->unique()->all();
        $items = DB::table('inventory_items as i')
            ->join('units_of_measure as u', 'u.id', '=', 'i.unit_of_measure_id')
            ->whereIn('i.id', $itemIds)->orderBy('i.name')
            ->get(['i.id', 'i.sku', 'i.name', 'u.code as unit']);

        $groups = [
            'received' => [InventoryMovementType::Opening],
            'returned' => [InventoryMovementType::ReturnIn],
            'sold' => [InventoryMovementType::Sale],
            'waste' => [InventoryMovementType::Waste],
            'adjusted' => [InventoryMovementType::AdjustmentIn, InventoryMovementType::AdjustmentOut],
            'transferred' => [InventoryMovementType::TransferIn, InventoryMovementType::TransferOut],
        ];
        $byItem = $moved->groupBy('inventory_item_id');
        $q = fn (mixed $v) => Quantity::fromString(is_numeric($v) ? bcadd((string) $v, '0', 3) : '0');

        $rows = [];
        $count = 0;
        foreach ($items as $item) {
            $lines = $byItem->get($item->id, collect());
            $count += (int) $lines->sum('n');
            $row = ['item' => $item->name, 'sku' => $item->sku, 'unit' => $item->unit, 'start' => $q($opening->get($item->id))->toString()];
            foreach ($groups as $key => $types) {
                $values = array_map(fn (InventoryMovementType $t) => $t->value, $types);
                $row[$key] = $lines->whereIn('movement_type', $values)->reduce(fn (Quantity $carry, \stdClass $l) => $carry->add($q($l->qty)), Quantity::zero())->toString();
            }
            $row['end'] = $q($closing->get($item->id))->toString();
            $rows[] = $row;
        }

        return [
            'cards' => [
                ['label' => 'Items with stock or movements', 'value' => (string) count($rows), 'type' => 'int'],
                ['label' => 'Movements', 'value' => (string) $count, 'type' => 'int'],
            ],
            'notes' => [
                'Start and end are the balances at the start of the first day and the end of the last day (Manila time). In is positive, out is negative.',
                'Adjusted and transferred are net (in minus out).',
            ],
            'tables' => [new ReportTable('items', 'Stock in and out', [
                ReportTable::col('item', 'Item'),
                ReportTable::col('sku', 'SKU'),
                ReportTable::col('unit', 'Unit'),
                ReportTable::col('start', 'Start', 'quantity'),
                ReportTable::col('received', 'Opening stock', 'quantity'),
                ReportTable::col('returned', 'Returned', 'quantity'),
                ReportTable::col('sold', 'Sold', 'quantity'),
                ReportTable::col('waste', 'Waste', 'quantity'),
                ReportTable::col('adjusted', 'Adjusted', 'quantity'),
                ReportTable::col('transferred', 'Transferred', 'quantity'),
                ReportTable::col('end', 'End', 'quantity'),
            ], $rows, null, 'No stock in these dates.')],
        ];
    }

    /**
     * Stock written off as waste in range, valued at the item's selling
     * price at that location when it was written off (D-191). Items with no
     * selling price (not linked to a product) count as zero.
     *
     * @return array{cards: list<array{label: string, value: string, type: string, note?: string}>, notes: list<string>, tables: list<ReportTable>}
     */
    private function waste(ReportFilters $f): array
    {
        [$start, $end] = $f->utcRange();
        $entries = DB::table('inventory_movements as m')
            ->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->join('units_of_measure as u', 'u.id', '=', 'i.unit_of_measure_id')
            ->join('branches as b', 'b.id', '=', 'm.branch_id')
            ->leftJoin('products as p', 'p.inventory_item_id', '=', 'm.inventory_item_id')
            ->leftJoin('users as us', 'us.id', '=', 'm.created_by')
            ->whereIn('m.branch_id', $this->branches($f))
            ->where('m.movement_type', InventoryMovementType::Waste->value)
            ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end)
            ->orderBy('m.created_at')->orderBy('m.id')
            ->get(['m.created_at', 'm.inventory_item_id', 'i.name as item', 'u.code as unit', 'b.code as location', 'm.quantity_delta', 'm.branch_id', 'p.id as product_id', 'm.reason', 'us.name as by']);

        $q = fn (mixed $v) => Quantity::fromString(bcadd(is_numeric($v) ? (string) $v : '0', '0', 3));
        $products = Product::query()->whereIn('id', $entries->pluck('product_id')->filter()->unique())->get()->keyBy('id');
        $locations = Branch::query()->whereIn('id', $entries->pluck('branch_id')->unique())->get()->keyBy('id');
        $price = function (\stdClass $e) use ($products, $locations): ?Money {
            $product = $products->get($e->product_id);
            $location = $locations->get($e->branch_id);

            return $product && $location ? $this->prices->current($product, null, $location, CarbonImmutable::parse($e->created_at, 'UTC')) : null;
        };
        $priced = $entries->map(fn (\stdClass $e) => [$e, $price($e)]);
        $value = fn (\stdClass $e, ?Money $unit) => $unit === null
            ? Money::zero()
            : $unit->multiply($q($e->quantity_delta)->negate());

        $items = [];
        foreach ($priced as [$e, $unit]) {
            $row = $items[$e->inventory_item_id] ?? ['item' => $e->item, 'unit' => $e->unit, 'entries' => 0, 'quantity' => Quantity::zero(), 'value' => Money::zero()];
            $row['entries']++;
            $row['quantity'] = $row['quantity']->add($q($e->quantity_delta)->negate());
            $row['value'] = $row['value']->add($value($e, $unit));
            $items[$e->inventory_item_id] = $row;
        }
        $total = Money::sum(array_map(fn (array $r) => $r['value'], $items));

        return [
            'cards' => [
                ['label' => 'Waste entries', 'value' => (string) $entries->count(), 'type' => 'int'],
                ['label' => 'Value at selling price', 'value' => $total->toDecimal(), 'type' => 'money', 'note' => 'what it would have sold for'],
            ],
            'notes' => [
                "Waste is valued at the item's selling price at that location on the day it was written off. Items without a selling price count as ₱0.00.",
            ],
            'tables' => [
                new ReportTable('by-item', 'By item', [
                    ReportTable::col('item', 'Item'),
                    ReportTable::col('unit', 'Unit'),
                    ReportTable::col('entries', 'Entries', 'int'),
                    ReportTable::col('quantity', 'Quantity', 'quantity'),
                    ReportTable::col('value', 'Value at selling price', 'money'),
                ], array_values(array_map(fn (array $r) => [
                    'item' => $r['item'],
                    'unit' => $r['unit'],
                    'entries' => $r['entries'],
                    'quantity' => $r['quantity']->toString(),
                    'value' => $r['value']->toDecimal(),
                ], $items)), ['item' => 'Total', 'value' => $total->toDecimal()], 'No waste in these dates.'),
                new ReportTable('entries', 'Waste entries', [
                    ReportTable::col('created_at', 'When', 'datetime'),
                    ReportTable::col('location', 'Location'),
                    ReportTable::col('item', 'Item'),
                    ReportTable::col('quantity', 'Quantity', 'quantity'),
                    ReportTable::col('unit', 'Unit'),
                    ReportTable::col('price', 'Selling price', 'money'),
                    ReportTable::col('value', 'Value', 'money'),
                    ReportTable::col('reason', 'Reason'),
                    ReportTable::col('by', 'By'),
                ], array_values($priced->map(fn (array $pair) => [
                    'created_at' => $this->iso($pair[0]->created_at),
                    'location' => $pair[0]->location,
                    'item' => $pair[0]->item,
                    'quantity' => $q($pair[0]->quantity_delta)->negate()->toString(),
                    'unit' => $pair[0]->unit,
                    'price' => $pair[1]?->toDecimal(),
                    'value' => $value($pair[0], $pair[1])->toDecimal(),
                    'reason' => $pair[0]->reason,
                    'by' => $pair[0]->by,
                ])->all()), null, 'No waste in these dates.'),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function branches(ReportFilters $f): array
    {
        return $f->branchIds === [] ? [0] : $f->branchIds;
    }

    /** A stored UTC timestamp as ISO 8601. */
    private function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }
}
