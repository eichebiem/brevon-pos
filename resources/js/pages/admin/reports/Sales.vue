<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import { computed } from 'vue';
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';
import { sales } from '@/routes/admin/reports';
import { exportMethod } from '@/routes/admin/reports/sales';
import type {
    CashierRow,
    CollectionRow,
    LocationOption,
    NamedRow,
    Option,
    PeriodRow,
    ProductRow,
    ReportFilters,
    SalesFigures,
} from '@/types/reports';

const props = defineProps<{
    filters: ReportFilters;
    options: {
        locations: LocationOption[];
        cashiers: Option[];
        categories: Option[];
        payment_methods: Option[];
    };
    summary: SalesFigures;
    periods: PeriodRow[];
    products: ProductRow[];
    categories: NamedRow[];
    cashiers: CashierRow[];
    payments: {
        rows: CollectionRow[];
        total: {
            received: string;
            waiting: string;
            given_back: string;
            net: string;
        };
    };
    canExport: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sales report', href: sales() }],
    },
});

const query = computed(() =>
    Object.fromEntries(
        Object.entries(props.filters).filter(([, v]) => v !== null && v !== ''),
    ),
);
const csv = (section: string, format: 'csv' | 'xlsx' = 'csv'): string =>
    exportMethod(section, { query: { ...query.value, format } }).url;

const locationLabel = computed(() =>
    props.filters.location === 'all'
        ? 'All my locations'
        : (props.options.locations.find(
              (l) => String(l.id) === props.filters.location,
          )?.label ?? ''),
);

/** A deduction shown with a minus sign, but never "−₱0.00". */
const minus = (value: string): string =>
    Number(value) > 0 ? `−${formatMoney(value)}` : formatMoney(value);

function printPage(): void {
    window.print();
}

const groups: { value: ReportFilters['group']; label: string }[] = [
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
];

const figureColumns: { key: keyof SalesFigures; label: string }[] = [
    { key: 'gross', label: 'Gross' },
    { key: 'discounts', label: 'Discounts' },
    { key: 'refunds', label: 'Refunds' },
    { key: 'net', label: 'Net' },
    { key: 'orders', label: 'Orders' },
];
const cell = (row: SalesFigures, key: keyof SalesFigures): string =>
    key === 'orders' || key === 'quantity'
        ? String(row[key])
        : formatMoney(row[key] as string);
</script>

<template>
    <Head title="Sales report" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Sales report
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ formatDate(filters.from) }} –
                    {{ formatDate(filters.to) }} · {{ locationLabel }}
                </p>
            </div>
            <Button variant="outline" class="print:hidden" @click="printPage"
                ><Printer aria-hidden="true" /> Print</Button
            >
        </header>

        <ReportFilterBar
            :url="sales().url"
            :filters="filters"
            :locations="options.locations"
            :cashiers="options.cashiers"
            :categories="options.categories"
            :payment-methods="options.payment_methods"
        />

        <section aria-labelledby="summary-heading">
            <h2 id="summary-heading" class="sr-only">Summary</h2>
            <dl
                class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6"
                data-test="sales-summary"
            >
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Gross sales</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(summary.gross) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Discounts</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ minus(summary.discounts) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Refunds</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ minus(summary.refunds) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Net sales</dt>
                    <dd
                        class="text-xl font-semibold tabular-nums"
                        data-test="summary-net"
                    >
                        {{ formatMoney(summary.net) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Orders</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ summary.orders }}
                    </dd>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <dt class="text-sm text-muted-foreground">Average order</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(summary.average) }}
                    </dd>
                </div>
            </dl>
            <details class="mt-2 text-sm text-muted-foreground">
                <summary class="cursor-pointer">
                    How these are worked out
                </summary>
                <ul class="mt-2 grid list-disc gap-1 pl-5">
                    <li>
                        A sale counts on its business date if it was paid (or is
                        waiting for its GCash check) and was not cancelled. A
                        GCash payment marked "not received" is left out.
                    </li>
                    <li>
                        <strong>Gross sales</strong>: item prices × quantity,
                        add-ons included, before discounts.
                    </li>
                    <li>
                        <strong>Discounts</strong>: discounts given on those
                        sales. <strong>Refunds</strong>: money given back on
                        those sales, whenever it was given back.
                    </li>
                    <li>
                        <strong>Net sales</strong> = gross − discounts −
                        refunds. <strong>Average order</strong> = net sales ÷
                        orders.<template v-if="$page.props.features?.tax_rules">
                            Tax shown apart:
                            {{ formatMoney(summary.tax) }}.</template
                        >
                    </li>
                    <li>
                        <strong>Collected</strong> (below): payments received on
                        these sales, and refunds handed back in these dates.
                    </li>
                    <li>To be confirmed with the accountant.</li>
                </ul>
            </details>
        </section>

        <section
            class="rounded-xl border border-border bg-card"
            aria-labelledby="periods-heading"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-border p-4"
            >
                <h2 id="periods-heading" class="font-semibold">By period</h2>
                <div class="flex flex-wrap items-center gap-2 print:hidden">
                    <div
                        class="flex overflow-hidden rounded-md border border-border"
                        role="group"
                        aria-label="Group by"
                    >
                        <Link
                            v-for="g in groups"
                            :key="g.value"
                            :href="
                                sales({ query: { ...query, group: g.value } })
                                    .url
                            "
                            class="px-3 py-1.5 text-sm"
                            :class="
                                filters.group === g.value
                                    ? 'bg-brevon-navy text-white'
                                    : 'hover:bg-secondary'
                            "
                            :aria-current="
                                filters.group === g.value ? 'true' : undefined
                            "
                            preserve-scroll
                            >{{ g.label }}</Link
                        >
                    </div>
                    <Button
                        v-if="canExport"
                        variant="outline"
                        size="sm"
                        as-child
                        ><a :href="csv('periods')"
                            ><Download aria-hidden="true" /> CSV</a
                        ></Button
                    >
                    <Button
                        v-if="canExport"
                        variant="outline"
                        size="sm"
                        as-child
                        ><a :href="csv('periods', 'xlsx')"
                            ><Download aria-hidden="true" /> Excel</a
                        ></Button
                    >
                </div>
            </div>
            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    data-test="periods-table"
                >
                    <thead class="bg-secondary text-secondary-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-semibold">
                                Period
                            </th>
                            <th
                                v-for="c in figureColumns"
                                :key="c.key"
                                scope="col"
                                class="px-4 py-2 text-right font-semibold"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!periods.length">
                            <td
                                :colspan="figureColumns.length + 1"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                No sales in these dates.
                            </td>
                        </tr>
                        <tr
                            v-for="row in periods"
                            :key="row.start"
                            class="border-t border-border"
                        >
                            <th scope="row" class="px-4 py-2 font-normal">
                                {{ row.period }}
                            </th>
                            <td
                                v-for="c in figureColumns"
                                :key="c.key"
                                class="px-4 py-2 text-right tabular-nums"
                            >
                                {{ cell(row, c.key) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section
                class="rounded-xl border border-border bg-card"
                aria-labelledby="products-heading"
            >
                <div
                    class="flex items-center justify-between border-b border-border p-4"
                >
                    <h2 id="products-heading" class="font-semibold">
                        By product
                    </h2>
                    <Button
                        v-if="canExport"
                        variant="outline"
                        size="sm"
                        as-child
                        class="print:hidden"
                        ><a :href="csv('products')"
                            ><Download aria-hidden="true" /> CSV</a
                        ></Button
                    >
                    <Button
                        v-if="canExport"
                        variant="outline"
                        size="sm"
                        as-child
                        class="print:hidden"
                        ><a :href="csv('products', 'xlsx')"
                            ><Download aria-hidden="true" /> Excel</a
                        ></Button
                    >
                </div>
                <div class="max-h-[32rem] overflow-auto print:max-h-none">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="sticky top-0 bg-secondary text-secondary-foreground"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-2 font-semibold">
                                    Product
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Sold
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Net
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in products"
                                :key="i"
                                class="border-t border-border"
                            >
                                <th scope="row" class="px-4 py-2 font-normal">
                                    {{ row.name
                                    }}<template v-if="row.variant">
                                        ({{ row.variant }})</template
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.category }}</span
                                    >
                                </th>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.quantity }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ formatMoney(row.net) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid content-start gap-6">
                <section
                    class="rounded-xl border border-border bg-card"
                    aria-labelledby="categories-heading"
                >
                    <div
                        class="flex items-center justify-between border-b border-border p-4"
                    >
                        <h2 id="categories-heading" class="font-semibold">
                            By category
                        </h2>
                        <Button
                            v-if="canExport"
                            variant="outline"
                            size="sm"
                            as-child
                            class="print:hidden"
                            ><a :href="csv('categories')"
                                ><Download aria-hidden="true" /> CSV</a
                            ></Button
                        >
                        <Button
                            v-if="canExport"
                            variant="outline"
                            size="sm"
                            as-child
                            class="print:hidden"
                            ><a :href="csv('categories', 'xlsx')"
                                ><Download aria-hidden="true" /> Excel</a
                            ></Button
                        >
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-secondary text-secondary-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-semibold">
                                    Category
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Orders
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Net
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in categories"
                                :key="i"
                                class="border-t border-border"
                            >
                                <th scope="row" class="px-4 py-2 font-normal">
                                    {{ row.name }}
                                </th>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.orders }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ formatMoney(row.net) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section
                    class="rounded-xl border border-border bg-card"
                    aria-labelledby="cashiers-heading"
                >
                    <div
                        class="flex items-center justify-between border-b border-border p-4"
                    >
                        <h2 id="cashiers-heading" class="font-semibold">
                            By cashier
                        </h2>
                        <Button
                            v-if="canExport"
                            variant="outline"
                            size="sm"
                            as-child
                            class="print:hidden"
                            ><a :href="csv('cashiers')"
                                ><Download aria-hidden="true" /> CSV</a
                            ></Button
                        >
                        <Button
                            v-if="canExport"
                            variant="outline"
                            size="sm"
                            as-child
                            class="print:hidden"
                            ><a :href="csv('cashiers', 'xlsx')"
                                ><Download aria-hidden="true" /> Excel</a
                            ></Button
                        >
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-secondary text-secondary-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-semibold">
                                    Cashier
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Shifts
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Orders
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-semibold"
                                >
                                    Net
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in cashiers"
                                :key="i"
                                class="border-t border-border"
                            >
                                <th scope="row" class="px-4 py-2 font-normal">
                                    {{ row.name }}
                                </th>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.shifts }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.orders }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ formatMoney(row.net) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>

        <section
            class="rounded-xl border border-border bg-card"
            aria-labelledby="payments-heading"
        >
            <div
                class="flex items-center justify-between border-b border-border p-4"
            >
                <h2 id="payments-heading" class="font-semibold">
                    Collected by payment method
                </h2>
                <Button
                    v-if="canExport"
                    variant="outline"
                    size="sm"
                    as-child
                    class="print:hidden"
                    ><a :href="csv('payments')"
                        ><Download aria-hidden="true" /> CSV</a
                    ></Button
                >
                <Button
                    v-if="canExport"
                    variant="outline"
                    size="sm"
                    as-child
                    class="print:hidden"
                    ><a :href="csv('payments', 'xlsx')"
                        ><Download aria-hidden="true" /> Excel</a
                    ></Button
                >
            </div>
            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    data-test="payments-table"
                >
                    <thead class="bg-secondary text-secondary-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-semibold">
                                Method
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-semibold"
                            >
                                Received
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-semibold"
                            >
                                Waiting for check
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-semibold"
                            >
                                Given back
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-semibold"
                            >
                                Net collected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in payments.rows"
                            :key="row.method"
                            class="border-t border-border"
                        >
                            <th scope="row" class="px-4 py-2 font-normal">
                                {{ row.method }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.payments }} payments ·
                                    {{ row.refunds }} refunds</span
                                >
                            </th>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(row.received) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(row.waiting) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ minus(row.given_back) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(row.net) }}
                            </td>
                        </tr>
                        <tr class="border-t-2 border-border font-semibold">
                            <th scope="row" class="px-4 py-2">Total</th>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(payments.total.received) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(payments.total.waiting) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ minus(payments.total.given_back) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatMoney(payments.total.net) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
