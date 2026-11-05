<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { History, PackagePlus, Search } from '@lucide/vue';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
import { movements, opening, stock } from '@/routes/admin/inventory';

type Row = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    on_hand: string;
    reorder_level: string;
    average_unit_cost: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    site: { id: number; code: string; name: string } | null;
    rows: Row[];
    search: string | null;
    awaitingOpening: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock on hand', href: stock() }] },
});

const { can } = usePermissions();
const query = ref(props.search ?? '');

function applySearch(): void {
    router.get(stock().url, query.value ? { search: query.value } : {}, {
        preserveState: true,
        replace: true,
    });
}

const isLow = (row: Row) =>
    Number(row.reorder_level) > 0 &&
    Number(row.on_hand) <= Number(row.reorder_level);
</script>

<template>
    <Head title="Stock on hand" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Stock on hand
                </h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="site"
                        >{{ site.code }} · {{ site.name }}. Every change is
                        recorded in stock movements.</template
                    >
                    <template v-else
                        >No operating location is selected.</template
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="movements()"
                        ><History aria-hidden="true" /> Stock movements</Link
                    >
                </Button>
                <Button
                    v-if="can('inventory.adjust') && awaitingOpening > 0"
                    as-child
                >
                    <Link :href="opening()"
                        ><PackagePlus aria-hidden="true" /> Enter opening
                        stock</Link
                    >
                </Button>
            </div>
        </header>

        <p
            v-if="site && awaitingOpening > 0"
            class="rounded-lg border border-border bg-secondary p-3 text-sm"
            role="note"
        >
            {{ awaitingOpening }}
            {{ awaitingOpening === 1 ? 'item has' : 'items have' }} no stock
            recorded here yet. Enter the opening count once; later changes come
            from production, sales and adjustments.
        </p>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="applySearch"
        >
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium"
                    >Name or SKU</label
                >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input id="search" v-model="query" class="pl-8" />
                </div>
            </div>
            <Button type="submit">Search</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Stock on hand
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
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Last change
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No stock recorded at this location yet.
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
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <span class="font-semibold tabular-nums">{{
                                formatQuantity(row.on_hand)
                            }}</span>
                            {{ row.unit }}
                            <StatusBadge
                                v-if="isLow(row)"
                                tone="warning"
                                class="ml-2"
                                >Low</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(row.average_unit_cost) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDateTime(row.updated_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
