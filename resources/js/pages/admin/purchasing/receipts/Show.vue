<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import {
    formatDate,
    formatDateTime,
    formatQuantity,
    formatUnitCost,
} from '@/lib/format';
import { show as showOrder } from '@/routes/admin/purchase-orders';
import { index } from '@/routes/admin/receipts';

type Line = {
    id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    quantity: string;
    unit_cost: string | null;
    condition: 'accepted' | 'damaged' | 'expired';
    condition_label: string;
};

defineProps<{
    receipt: {
        id: number;
        number: string;
        purchase_order: { id: number; po_number: string } | null;
        supplier: { supplier_code: string; name: string } | null;
        delivery_reference: string | null;
        received_at: string;
        received_by: string | null;
        location: { code: string; name: string } | null;
        notes: string | null;
        over_receipt_approved_by: string | null;
        lines: Line[];
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] },
});
</script>

<template>
    <Head :title="`Delivery ${receipt.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Delivery {{ receipt.number }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ receipt.location?.code }} · received by
                {{ receipt.received_by }}
                {{ formatDateTime(receipt.received_at) }}
            </p>
            <p class="mt-1 text-sm">
                <template v-if="receipt.purchase_order"
                    >Against
                    <Link
                        :href="showOrder(receipt.purchase_order.id)"
                        class="underline"
                        >{{ receipt.purchase_order.po_number }}</Link
                    ></template
                ><template v-else>No purchase order</template> ·
                {{ receipt.supplier?.name ?? 'No supplier'
                }}<template v-if="receipt.delivery_reference">
                    · ref {{ receipt.delivery_reference }}</template
                >
            </p>
            <p v-if="receipt.notes" class="mt-1 text-sm">
                <span class="text-muted-foreground">Notes:</span>
                {{ receipt.notes }}
            </p>
            <p
                v-if="receipt.over_receipt_approved_by"
                class="mt-1 text-sm text-amber-900"
                data-test="over-receipt"
            >
                More than ordered was accepted by
                {{ receipt.over_receipt_approved_by }}.
            </p>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Delivery lines
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
                            Quantity
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Cost per unit
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Condition
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in receipt.lines"
                        :key="line.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ line.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ line.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(line.quantity) }} {{ line.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatUnitCost(line.unit_cost) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="
                                    line.condition === 'accepted'
                                        ? 'success'
                                        : 'danger'
                                "
                                >{{
                                    line.condition === 'accepted'
                                        ? 'Added to stock'
                                        : `${line.condition_label} — not added`
                                }}</StatusBadge
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
