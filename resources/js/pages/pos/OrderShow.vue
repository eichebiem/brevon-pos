<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import CancelOrderDialog from '@/components/orders/CancelOrderDialog.vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney } from '@/lib/format';
import { advance } from '@/routes/orders';
import { verify } from '@/routes/payments';
import { index, receipt as receiptRoute, reprint } from '@/routes/pos/orders';
import type { Receipt } from '@/types/pos';

type Workflow = {
    status: string;
    status_label: string;
    next_status: string | null;
    payment_status: string;
    cancellation: { at: string; by: string | null; reason: string } | null;
    history: {
        from: string | null;
        to: string;
        by: string | null;
        at: string;
        notes: string | null;
    }[];
    pending_payments: {
        id: number;
        method: string;
        amount: string;
        reference_number: string;
    }[];
    has_cash: boolean;
    can_advance: boolean;
    can_cancel: boolean;
    can_verify: boolean;
};

const props = defineProps<{ receipt: Receipt; workflow: Workflow }>();
const printing = ref(false);
const busy = ref(false);

// FR-034: reprinting is logged in the audit trail and creates nothing else.
function printAgain(): void {
    printing.value = true;
    router.post(
        reprint(props.receipt.order.id).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                window.open(
                    receiptRoute(props.receipt.order.id, { query: { auto: 1 } })
                        .url,
                    '_blank',
                    'noopener',
                );
            },
            onFinish: () => {
                printing.value = false;
            },
        },
    );
}

const nextLabel: Record<string, string> = {
    preparing: 'Start preparing',
    ready: 'Mark ready',
    completed: 'Handed over',
};

const tone: Record<string, 'success' | 'info' | 'danger' | 'neutral'> = {
    completed: 'success',
    cancelled: 'danger',
    refunded: 'neutral',
};

function post(url: string, data: Record<string, string> = {}): void {
    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        onError: (errors) => {
            toast.error(Object.values(errors)[0] ?? 'Could not save.');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

const cancelOpen = ref(false);
</script>

<template>
    <Head :title="`Order ${receipt.order.order_number}`" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Order {{ receipt.order.order_number }}
                </h1>
                <StatusBadge
                    :tone="tone[workflow.status] ?? 'info'"
                    data-test="order-status"
                    >{{ workflow.status_label }}</StatusBadge
                >
            </div>
            <div class="flex gap-2">
                <Button variant="outline" as-child class="min-h-11">
                    <Link :href="index()"
                        ><ArrowLeft aria-hidden="true" /> Orders</Link
                    >
                </Button>
                <Button
                    class="min-h-11"
                    :disabled="printing"
                    data-test="pos-reprint"
                    @click="printAgain"
                    ><Printer aria-hidden="true" /> Print again</Button
                >
            </div>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[auto_1fr]">
            <div
                class="mx-auto w-fit rounded-lg border border-border bg-white p-2 shadow-sm"
            >
                <ReceiptView :receipt="receipt" />
            </div>

            <div class="grid gap-4">
                <section
                    v-if="
                        (workflow.can_advance && workflow.next_status) ||
                        workflow.can_cancel
                    "
                    class="flex flex-wrap gap-2 rounded-xl border border-border bg-card p-4"
                    aria-label="Order actions"
                >
                    <Button
                        v-if="workflow.can_advance && workflow.next_status"
                        class="min-h-11"
                        :disabled="busy"
                        data-test="order-advance"
                        @click="
                            post(advance(receipt.order.id).url, {
                                from: workflow.status,
                            })
                        "
                        >{{ nextLabel[workflow.next_status] }}</Button
                    >
                    <Button
                        v-if="workflow.can_cancel"
                        variant="outline"
                        class="min-h-11 text-red-700"
                        data-test="order-cancel"
                        @click="cancelOpen = true"
                        >Cancel order</Button
                    >
                </section>

                <section
                    v-if="workflow.cancellation"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"
                >
                    <p class="font-semibold">
                        Cancelled
                        {{ formatDateTime(workflow.cancellation.at) }} by
                        {{ workflow.cancellation.by }}
                    </p>
                    <p>Reason: {{ workflow.cancellation.reason }}</p>
                    <p>The payment was refunded.</p>
                </section>

                <section
                    v-if="workflow.pending_payments.length"
                    class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    <h2 class="mb-2 font-semibold">Waiting for verification</h2>
                    <div
                        v-for="payment in workflow.pending_payments"
                        :key="payment.id"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span
                            >{{ payment.method }}
                            {{ formatMoney(payment.amount) }} · ref
                            <strong class="font-mono">{{
                                payment.reference_number
                            }}</strong></span
                        >
                        <Button
                            v-if="workflow.can_verify"
                            size="sm"
                            :disabled="busy"
                            data-test="order-verify"
                            @click="post(verify(payment.id).url)"
                            >Received</Button
                        >
                    </div>
                </section>

                <section
                    class="rounded-xl border border-border bg-card p-4"
                    aria-labelledby="history"
                >
                    <h2 id="history" class="mb-3 font-semibold">
                        Status history
                    </h2>
                    <ol class="grid gap-2 text-sm">
                        <li
                            v-for="(row, i) in workflow.history"
                            :key="i"
                            class="flex flex-wrap justify-between gap-x-4 border-b border-border pb-2 last:border-0"
                        >
                            <span>
                                <template v-if="row.from"
                                    >{{ row.from }} →
                                </template>
                                <strong>{{ row.to }}</strong>
                                <span
                                    v-if="row.notes"
                                    class="block text-muted-foreground"
                                    >{{ row.notes }}</span
                                >
                            </span>
                            <span class="text-muted-foreground"
                                >{{ row.by }} ·
                                {{ formatDateTime(row.at) }}</span
                            >
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </div>

    <CancelOrderDialog
        v-model:open="cancelOpen"
        :order="{
            id: receipt.order.id,
            order_number: receipt.order.order_number,
            grand_total: receipt.order.grand_total,
        }"
    />
</template>
