<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { formatMoney, formatQuantity, formatUnitCost } from '@/lib/format';
import { valuation } from '@/routes/admin/inventory';

type Row = {
    id: number;
    sku: string;
    name: string;
    type: string;
    unit: string | null;
    on_hand: string;
    average_unit_cost: string | null;
    value: string | null;
};

defineProps<{
    site: { id: number; code: string; name: string } | null;
    rows: Row[];
    total: string;
    uncosted: number;
    byLocation: {
        id: number;
        code: string;
        name: string;
        value: string;
        uncosted: number;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock value', href: valuation() }] },
});
</script>

<template>
    <Head title="Stock value" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Stock value
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="site"
                    >{{ site.code }} · {{ site.name }}. </template
                >Stock on hand × weighted average cost. Each delivery updates
                the average; counts, waste and sales do not change it.
            </p>
        </header>

        <section
            v-if="byLocation.length > 1"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            aria-label="Value by location"
        >
            <div
                v-for="loc in byLocation"
                :key="loc.id"
                class="rounded-xl border border-border bg-card p-4"
            >
                <p class="text-xs text-muted-foreground">
                    {{ loc.code }} · {{ loc.name }}
                </p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ formatMoney(loc.value) }}
                </p>
                <p v-if="loc.uncosted" class="text-xs text-amber-900">
                    {{ loc.uncosted }} item(s) without a cost
                </p>
            </div>
        </section>

        <p
            v-if="uncosted > 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
            role="status"
        >
            {{ uncosted }}
            {{ uncosted === 1 ? 'item has' : 'items have' }} stock but no cost
            yet, so {{ uncosted === 1 ? 'it is' : 'they are' }} left out of the
            total. A delivery with a cost sets it.
        </p>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Stock value by item
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            On hand
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Average cost
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Value
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No stock on hand here.
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.on_hand) }} {{ row.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{
                                row.average_unit_cost === null
                                    ? 'No cost'
                                    : formatUnitCost(row.average_unit_cost)
                            }}
                        </td>
                        <td
                            class="px-4 py-3 text-right tabular-nums"
                            :data-test="`value-${row.sku}`"
                        >
                            {{ formatMoney(row.value) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr class="border-t border-border font-semibold">
                        <td colspan="3" class="px-4 py-3 text-right">Total</td>
                        <td
                            class="px-4 py-3 text-right tabular-nums"
                            data-test="value-total"
                        >
                            {{ formatMoney(total) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
