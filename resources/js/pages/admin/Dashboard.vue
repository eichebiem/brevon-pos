<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { dashboard } from '@/routes';
import { stock } from '@/routes/admin/inventory';
import { sales } from '@/routes/admin/reports';
import type {
    LocationOption,
    ProductRow,
    ReportFilters,
    SalesFigures,
} from '@/types/reports';

const props = defineProps<{
    filters: ReportFilters;
    options: { locations: LocationOption[] };
    figures: SalesFigures;
    days: { start: string; net: string; orders: number }[];
    top_products: ProductRow[];
    low_stock: number;
    canSeeReports: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const maxNet = computed(() =>
    Math.max(1, ...props.days.map((d) => Number(d.net))),
);
const reportQuery = computed(() => ({
    from: props.filters.from,
    to: props.filters.to,
    location: props.filters.location,
}));
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Dashboard
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ formatDate(filters.from) }}
                    <template v-if="filters.to !== filters.from">
                        – {{ formatDate(filters.to) }}</template
                    >
                    · sales that were paid (or are waiting for a GCash check)
                    and not cancelled.
                </p>
            </div>
            <Link
                v-if="canSeeReports"
                :href="sales({ query: reportQuery })"
                class="text-sm font-medium text-brevon-navy underline underline-offset-4 dark:text-brevon-cream"
                >Full sales report</Link
            >
        </header>

        <ReportFilterBar
            :url="dashboard().url"
            :filters="filters"
            :locations="options.locations"
        />

        <dl
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
            data-test="dashboard-kpis"
        >
            <div class="rounded-xl border border-border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Gross sales</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatMoney(figures.gross) }}
                </dd>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Net sales</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    data-test="kpi-net"
                >
                    {{ formatMoney(figures.net) }}
                </dd>
                <p class="text-xs text-muted-foreground">
                    after {{ formatMoney(figures.discounts) }} discounts and
                    {{ formatMoney(figures.refunds) }} refunds
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Orders</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ figures.orders }}
                </dd>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Average order</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatMoney(figures.average) }}
                </dd>
                <p class="text-xs text-muted-foreground">net sales ÷ orders</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Low stock</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :class="low_stock > 0 ? 'text-red-700' : ''"
                >
                    {{ low_stock }}
                </dd>
                <Link
                    :href="stock({ query: { low: 1 } })"
                    class="text-xs underline underline-offset-4"
                    >items at or below reorder level now</Link
                >
            </div>
        </dl>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <section
                class="rounded-xl border border-border bg-card p-4"
                aria-labelledby="daily-heading"
            >
                <h2 id="daily-heading" class="mb-3 font-semibold">
                    Net sales by day
                </h2>
                <p v-if="!days.length" class="text-sm text-muted-foreground">
                    No sales in these dates.
                </p>
                <ol v-else class="grid gap-1.5" data-test="dashboard-days">
                    <li
                        v-for="d in days"
                        :key="d.start"
                        class="grid grid-cols-[6.5rem_1fr_6rem] items-center gap-2 text-sm"
                    >
                        <span class="text-muted-foreground">{{
                            formatDate(d.start)
                        }}</span>
                        <span
                            class="h-4 rounded-sm bg-brevon-navy/80 dark:bg-brevon-cream/80"
                            :style="{
                                width: `${Math.max(1, (Number(d.net) / maxNet) * 100)}%`,
                            }"
                            aria-hidden="true"
                        />
                        <span class="text-right tabular-nums"
                            >{{ formatMoney(d.net) }}
                            <span class="sr-only"
                                >from {{ d.orders }} orders</span
                            ></span
                        >
                    </li>
                </ol>
            </section>

            <section
                class="rounded-xl border border-border bg-card p-4"
                aria-labelledby="top-heading"
            >
                <h2 id="top-heading" class="mb-3 font-semibold">
                    Best sellers
                </h2>
                <p
                    v-if="!top_products.length"
                    class="text-sm text-muted-foreground"
                >
                    No sales in these dates.
                </p>
                <ol v-else class="grid gap-2 text-sm">
                    <li
                        v-for="(p, i) in top_products"
                        :key="i"
                        class="flex justify-between gap-3"
                    >
                        <span
                            >{{ p.name
                            }}<template v-if="p.variant">
                                ({{ p.variant }})</template
                            >
                            <span class="block text-xs text-muted-foreground"
                                >{{ p.quantity }} sold</span
                            ></span
                        >
                        <span class="tabular-nums">{{
                            formatMoney(p.net)
                        }}</span>
                    </li>
                </ol>
            </section>
        </div>
    </div>
</template>
