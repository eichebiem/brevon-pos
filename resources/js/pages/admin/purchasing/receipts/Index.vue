<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { show as showOrder } from '@/routes/admin/purchase-orders';
import { create, index, show } from '@/routes/admin/receipts';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    number: string;
    purchase_order: { id: number; po_number: string } | null;
    supplier: { supplier_code: string; name: string } | null;
    delivery_reference: string | null;
    received_at: string;
    received_by: string | null;
    items_count: number;
};

defineProps<{ receipts: Paginated<Row>; canReceiveDirect: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] },
});
</script>

<template>
    <Head title="Deliveries" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Deliveries
                </h1>
                <p class="text-sm text-muted-foreground">
                    Goods received at the main branch. To receive against an
                    order, open the purchase order and choose Receive delivery.
                </p>
            </div>
            <Button v-if="canReceiveDirect" variant="outline" as-child>
                <Link :href="create()" data-test="receive-direct"
                    ><Plus aria-hidden="true" /> Receive without an order</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Deliveries
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Delivery
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Order
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Supplier
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Received
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="receipts.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            Nothing received yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in receipts.data"
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
                                {{ row.items_count }} line(s)<template
                                    v-if="row.delivery_reference"
                                >
                                    · ref {{ row.delivery_reference }}</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                v-if="row.purchase_order"
                                :href="showOrder(row.purchase_order.id)"
                                class="underline-offset-2 hover:underline"
                                >{{ row.purchase_order.po_number }}</Link
                            >
                            <span v-else class="text-muted-foreground"
                                >No order</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ row.supplier?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.received_at) }}
                            <p class="text-xs text-muted-foreground">
                                {{ row.received_by }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="receipts" noun="deliveries" />
    </div>
</template>
