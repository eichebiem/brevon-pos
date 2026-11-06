<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PackageCheck, Pencil, Send } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    formatQuantity,
    formatUnitCost,
} from '@/lib/format';
import { cancel, edit, index, submit } from '@/routes/admin/purchase-orders';
import {
    create as receive,
    show as showReceipt,
} from '@/routes/admin/receipts';
import { orderTone, type OrderRow } from '@/types/purchasing';

type Line = {
    id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    quantity_ordered: string;
    quantity_received: string;
    quantity_due: string;
    unit_cost: string;
    line_total: string;
};

const props = defineProps<{
    order: OrderRow & {
        notes: string | null;
        location: { code: string; name: string } | null;
        supplier_details: {
            contact_person: string | null;
            phone: string | null;
            email: string | null;
            payment_terms: string | null;
        } | null;
        approved_by: string | null;
        ordered_at: string | null;
        cancelled_by: string | null;
        cancelled_at: string | null;
        cancel_reason: string | null;
        lines: Line[];
        receipts: {
            id: number;
            number: string;
            received_at: string;
            received_by: string | null;
            delivery_reference: string | null;
        }[];
    };
    can: { edit: boolean; submit: boolean; cancel: boolean; receive: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] },
});

const sending = ref(false);

function send(): void {
    sending.value = true;
    router.post(
        submit(props.order.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not send.');
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

const cancelOpen = ref(false);
const cancelForm = useForm({ reason: '' });

function confirmCancel(): void {
    cancelForm.post(cancel(props.order.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Purchase order ${order.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1
                        class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                    >
                        {{ order.number }}
                    </h1>
                    <StatusBadge
                        :tone="orderTone[order.status]"
                        data-test="po-status"
                        >{{ order.status_label }}</StatusBadge
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ order.supplier?.name }} ({{
                        order.supplier?.supplier_code
                    }}) · for {{ order.location?.code }} · created by
                    {{ order.created_by }}
                    {{ formatDateTime(order.created_at) }}
                </p>
                <p
                    v-if="order.supplier_details"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        [
                            order.supplier_details.contact_person,
                            order.supplier_details.phone,
                            order.supplier_details.email,
                            order.supplier_details.payment_terms,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.edit" variant="outline" as-child>
                    <Link :href="edit(order.id)"
                        ><Pencil aria-hidden="true" /> Edit draft</Link
                    >
                </Button>
                <Button
                    v-if="can.submit"
                    :disabled="sending"
                    data-test="po-submit"
                    @click="send"
                >
                    <Spinner v-if="sending" /><Send v-else aria-hidden="true" />
                    Approve and mark ordered
                </Button>
                <Button v-if="can.receive" as-child data-test="po-receive">
                    <Link :href="receive({ query: { order: order.id } })"
                        ><PackageCheck aria-hidden="true" /> Receive
                        delivery</Link
                    >
                </Button>
                <Button
                    v-if="can.cancel"
                    variant="outline"
                    class="text-red-700"
                    data-test="po-cancel"
                    @click="cancelOpen = !cancelOpen"
                    >Cancel order</Button
                >
            </div>
        </header>

        <dl class="grid max-w-3xl gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div>
                <dt class="inline text-muted-foreground">Expected:</dt>
                <dd class="inline">{{ formatDate(order.expected_at) }}</dd>
            </div>
            <div v-if="order.approved_by">
                <dt class="inline text-muted-foreground">Approved:</dt>
                <dd class="inline">
                    {{ order.approved_by }}
                    {{ formatDateTime(order.ordered_at) }}
                </dd>
            </div>
            <div v-if="order.notes" class="sm:col-span-2">
                <dt class="inline text-muted-foreground">Notes:</dt>
                <dd class="inline">{{ order.notes }}</dd>
            </div>
        </dl>

        <section
            v-if="order.cancelled_at"
            class="max-w-3xl rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"
        >
            <p class="font-semibold">
                Cancelled {{ formatDateTime(order.cancelled_at) }} by
                {{ order.cancelled_by }}
            </p>
            <p>Reason: {{ order.cancel_reason }}</p>
            <p v-if="order.receipts.length">
                Stock already received stays in stock.
            </p>
        </section>

        <form
            v-if="cancelOpen"
            class="grid max-w-xl gap-3 rounded-xl border border-border bg-card p-5"
            novalidate
            @submit.prevent="confirmCancel"
        >
            <h2 class="font-semibold">Cancel {{ order.number }}</h2>
            <p class="text-sm text-muted-foreground">
                Nothing more can be received against it. Deliveries already
                received stay in stock.
            </p>
            <div class="grid gap-2">
                <Label for="cancel-reason">Reason</Label>
                <textarea
                    id="cancel-reason"
                    v-model="cancelForm.reason"
                    rows="2"
                    maxlength="500"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <InputError
                    :message="
                        cancelForm.errors.reason ??
                        (cancelForm.errors as Record<string, string>).order
                    "
                />
            </div>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    :disabled="
                        cancelForm.processing ||
                        cancelForm.reason.trim().length < 3
                    "
                    data-test="po-cancel-confirm"
                    >Cancel order</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    @click="cancelOpen = false"
                    >Keep it</Button
                >
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Order lines
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
                            Ordered
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Received
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Still due
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Cost per unit
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in order.lines"
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
                            {{ formatQuantity(line.quantity_ordered) }}
                            {{ line.unit }}
                        </td>
                        <td
                            class="px-4 py-3 text-right tabular-nums"
                            :data-test="`po-received-${line.sku}`"
                        >
                            {{ formatQuantity(line.quantity_received) }}
                            {{ line.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(line.quantity_due) }}
                            {{ line.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatUnitCost(line.unit_cost) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(line.line_total) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-border font-semibold">
                        <td colspan="5" class="px-4 py-3 text-right">
                            Order total
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(order.subtotal) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <section
            class="max-w-3xl rounded-xl border border-border bg-card p-4"
            aria-labelledby="deliveries"
        >
            <h2 id="deliveries" class="mb-3 font-semibold">Deliveries</h2>
            <p
                v-if="order.receipts.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing received yet.
            </p>
            <ul v-else class="grid gap-2 text-sm">
                <li
                    v-for="r in order.receipts"
                    :key="r.id"
                    class="flex flex-wrap justify-between gap-x-4 border-b border-border pb-2 last:border-0"
                >
                    <Link
                        :href="showReceipt(r.id)"
                        class="font-medium underline-offset-2 hover:underline"
                        >{{ r.number }}</Link
                    >
                    <span class="text-muted-foreground"
                        ><template v-if="r.delivery_reference"
                            >ref {{ r.delivery_reference }} · </template
                        >{{ r.received_by }} ·
                        {{ formatDateTime(r.received_at) }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
