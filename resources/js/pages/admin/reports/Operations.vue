<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import { computed } from 'vue';
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue';
import { Button } from '@/components/ui/button';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    formatQuantity,
} from '@/lib/format';
import { exportMethod, show } from '@/routes/admin/reports';
import type {
    LocationOption,
    Option,
    ReportCard,
    ReportColumn,
    ReportFilters,
    ReportTableData,
} from '@/types/reports';

const props = defineProps<{
    report: { key: string; title: string; filters: string[] };
    filters: ReportFilters;
    options: {
        locations: LocationOption[];
        cashiers: Option[] | null;
        payment_methods: Option[] | null;
    };
    cards: ReportCard[];
    notes: string[];
    tables: ReportTableData[];
    canExport: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [] } });

const query = computed(() =>
    Object.fromEntries(
        Object.entries(props.filters).filter(
            ([k, v]) => v !== null && v !== '' && k !== 'group',
        ),
    ),
);
const download = (table: string, format: 'csv' | 'xlsx'): string =>
    exportMethod([props.report.key, table], {
        query: { ...query.value, format },
    }).url;

const locationLabel = computed(() =>
    props.filters.location === 'all'
        ? 'All my locations'
        : (props.options.locations.find(
              (l) => String(l.id) === props.filters.location,
          )?.label ?? ''),
);

function format(
    value: string | number | null | undefined,
    type: ReportColumn['type'],
): string {
    if (value === null || value === undefined || value === '') {
        return type === 'text' ? '' : '—';
    }
    switch (type) {
        case 'money':
            return formatMoney(String(value));
        case 'quantity':
            return formatQuantity(String(value));
        case 'percent':
            return `${value}%`;
        case 'date':
            return formatDate(String(value));
        case 'datetime':
            return formatDateTime(String(value));
        default:
            return String(value);
    }
}
const numeric = (type: ReportColumn['type']): boolean =>
    ['money', 'quantity', 'int', 'percent'].includes(type);

function printPage(): void {
    window.print();
}
</script>

<template>
    <Head :title="report.title" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ report.title }}
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
            :url="show(report.key).url"
            :filters="filters"
            :locations="options.locations"
            :cashiers="options.cashiers ?? undefined"
            :payment-methods="options.payment_methods ?? undefined"
        />

        <dl
            v-if="cards.length"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            data-test="report-cards"
        >
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-xl border border-border bg-card p-4"
            >
                <dt class="text-sm text-muted-foreground">{{ card.label }}</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ format(card.value, card.type) }}
                </dd>
                <p v-if="card.note" class="text-xs text-muted-foreground">
                    {{ card.note }}
                </p>
            </div>
        </dl>

        <ul
            v-if="notes.length"
            class="grid list-disc gap-1 pl-5 text-sm text-muted-foreground"
        >
            <li v-for="(note, i) in notes" :key="i">{{ note }}</li>
        </ul>

        <section
            v-for="table in tables"
            :key="table.key"
            class="rounded-xl border border-border bg-card"
            :aria-labelledby="`table-${table.key}`"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-border p-4"
            >
                <h2 :id="`table-${table.key}`" class="font-semibold">
                    {{ table.title }}
                </h2>
                <div v-if="canExport" class="flex gap-2 print:hidden">
                    <Button variant="outline" size="sm" as-child
                        ><a :href="download(table.key, 'csv')"
                            ><Download aria-hidden="true" /> CSV</a
                        ></Button
                    >
                    <Button variant="outline" size="sm" as-child
                        ><a :href="download(table.key, 'xlsx')"
                            ><Download aria-hidden="true" /> Excel</a
                        ></Button
                    >
                </div>
            </div>
            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    :data-test="`table-${table.key}`"
                >
                    <thead class="bg-secondary text-secondary-foreground">
                        <tr>
                            <th
                                v-for="c in table.columns"
                                :key="c.key"
                                scope="col"
                                class="px-3 py-2 font-semibold whitespace-nowrap"
                                :class="numeric(c.type) ? 'text-right' : ''"
                            >
                                {{ c.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!table.rows.length">
                            <td
                                :colspan="table.columns.length"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                {{ table.empty }}
                            </td>
                        </tr>
                        <tr
                            v-for="(row, i) in table.rows"
                            :key="i"
                            class="border-t border-border"
                        >
                            <td
                                v-for="c in table.columns"
                                :key="c.key"
                                class="px-3 py-2"
                                :class="
                                    numeric(c.type)
                                        ? 'text-right whitespace-nowrap tabular-nums'
                                        : ''
                                "
                            >
                                {{ format(row[c.key], c.type) }}
                            </td>
                        </tr>
                        <tr
                            v-if="table.totals && table.rows.length"
                            class="border-t-2 border-border font-semibold"
                        >
                            <td
                                v-for="c in table.columns"
                                :key="c.key"
                                class="px-3 py-2"
                                :class="
                                    numeric(c.type)
                                        ? 'text-right tabular-nums'
                                        : ''
                                "
                            >
                                {{
                                    table.totals[c.key] === undefined
                                        ? ''
                                        : format(table.totals[c.key], c.type)
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
