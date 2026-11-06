<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney, selectClass } from '@/lib/format';
import { create, index, show } from '@/routes/admin/purchase-orders';
import type { Paginated } from '@/types/auth';
import { orderTone, type OrderRow } from '@/types/purchasing';

const props = defineProps<{
    orders: Paginated<OrderRow>;
    filters: { status?: string; supplier?: string };
    statuses: { value: string; label: string }[];
    suppliers: { id: number; supplier_code: string; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] },
});

const { can } = usePermissions();
const form = reactive({
    status: props.filters.status ?? '',
    supplier: props.filters.supplier ? String(props.filters.supplier) : '',
});

function apply(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Purchase orders" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Purchase orders
                </h1>
                <p class="text-sm text-muted-foreground">
                    Orders to suppliers for the main branch. A manager approves
                    a draft to send it; deliveries are then received against it,
                    all at once or in parts.
                </p>
            </div>
            <Button v-if="can('purchase-orders.create')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New purchase order</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="form.status" :class="selectClass">
                    <option value="">All</option>
                    <option
                        v-for="s in statuses"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="supplier" class="text-xs font-medium"
                    >Supplier</label
                >
                <select
                    id="supplier"
                    v-model="form.supplier"
                    :class="selectClass"
                >
                    <option value="">All suppliers</option>
                    <option
                        v-for="s in suppliers"
                        :key="s.id"
                        :value="String(s.id)"
                    >
                        {{ s.name }}
                    </option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Purchase orders
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Order
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Supplier
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Expected
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Total
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="orders.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No purchase orders yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in orders.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(row.id)"
                                class="font-medium underline-offset-2 hover:underline"
                                >{{ row.number }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                {{ row.items_count }} item(s) ·
                                {{ row.created_by }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ row.supplier?.name }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{
                                row.expected_at
                                    ? formatDate(row.expected_at)
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(row.subtotal) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="orderTone[row.status]">{{
                                row.status_label
                            }}</StatusBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="orders" noun="orders" />
    </div>
</template>
