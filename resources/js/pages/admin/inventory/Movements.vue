<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatQuantity, selectClass } from '@/lib/format';
import { movements as movementsRoute, stock } from '@/routes/admin/inventory';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    created_at: string;
    sku: string | null;
    item: string | null;
    type: string;
    type_label: string;
    quantity: string;
    balance_after: string;
    reason: string | null;
    by: string | null;
};

const props = defineProps<{
    site: { id: number; code: string; name: string } | null;
    movements: Paginated<Row>;
    filters: { item?: number; type?: string; from?: string; to?: string };
    items: { id: number; sku: string; name: string }[];
    types: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stock on hand', href: stock() },
            { title: 'Stock movements', href: movementsRoute() },
        ],
    },
});

const form = reactive({
    item: props.filters.item ? String(props.filters.item) : '',
    type: props.filters.type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function applyFilters(): void {
    router.get(
        movementsRoute().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

function resetFilters(): void {
    Object.assign(form, { item: '', type: '', from: '', to: '' });
    applyFilters();
}
</script>

<template>
    <Head title="Stock movements" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Stock movements
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="site"
                    >{{ site.code }} · {{ site.name }}. Movements are never
                    edited or deleted; corrections are new entries.</template
                >
                <template v-else>No operating location is selected.</template>
            </p>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1">
                <label for="item" class="text-xs font-medium">Item</label>
                <select id="item" v-model="form.item" :class="selectClass">
                    <option value="">All items</option>
                    <option
                        v-for="i in items"
                        :key="i.id"
                        :value="String(i.id)"
                    >
                        {{ i.name }} ({{ i.sku }})
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="type" class="text-xs font-medium">Type</label>
                <select id="type" v-model="form.type" :class="selectClass">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="from" class="text-xs font-medium">From</label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="grid gap-1">
                <label for="to" class="text-xs font-medium">To</label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
            <Button type="submit">Apply</Button>
            <Button type="button" variant="ghost" @click="resetFilters"
                >Reset</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Stock movements
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            When
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Type
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Change
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Balance after
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">By</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="movements.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No stock movements match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="row in movements.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.created_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.item }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ row.type_label }}
                            <p
                                v-if="
                                    row.reason && row.reason !== row.type_label
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.reason }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 text-right font-semibold tabular-nums"
                            :class="
                                row.quantity.startsWith('-')
                                    ? 'text-destructive'
                                    : 'text-emerald-700 dark:text-emerald-400'
                            "
                        >
                            {{ row.quantity.startsWith('-') ? '' : '+'
                            }}{{ formatQuantity(row.quantity) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.balance_after) }}
                        </td>
                        <td class="px-4 py-3">{{ row.by ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="movements" noun="movements" />
    </div>
</template>
