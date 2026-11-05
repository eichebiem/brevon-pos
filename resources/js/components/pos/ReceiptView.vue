<script setup lang="ts">
import { formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
import type { Receipt } from '@/types/pos';

defineProps<{ receipt: Receipt }>();

const paymentStatus: Record<string, string> = {
    verified: 'Paid',
    pending: 'Waiting for verification',
    failed: 'Failed',
};
</script>

<template>
    <!-- 80 mm order receipt (FR-033/FR-034, D-052). Not an official receipt/invoice (D-037, FR-087). -->
    <article
        class="receipt mx-auto w-[72mm] bg-white px-1 py-3 font-mono text-[11px] leading-snug text-black"
        data-test="receipt"
    >
        <header class="text-center">
            <img
                v-if="receipt.store.logo_url"
                :src="receipt.store.logo_url"
                alt=""
                class="mx-auto mb-1 size-14 rounded-full object-cover grayscale"
            />
            <p class="text-sm font-bold">{{ receipt.store.name }}</p>
            <p>{{ receipt.location.name }}</p>
            <p v-if="receipt.location.address">
                {{ receipt.location.address }}
            </p>
            <p v-if="receipt.location.phone">{{ receipt.location.phone }}</p>
            <p class="mt-2 border-y border-dashed border-black py-1 font-bold">
                {{ receipt.title }}
            </p>
        </header>

        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-2">
            <dt>Order</dt>
            <dd class="text-right font-bold">
                {{ receipt.order.order_number }}
            </dd>
            <dt>Date</dt>
            <dd class="text-right">
                {{ formatDateTime(receipt.order.created_at) }}
            </dd>
            <dt>Type</dt>
            <dd class="text-right">{{ receipt.order.order_type_label }}</dd>
            <template v-if="receipt.order.pickup_at">
                <dt>Pickup</dt>
                <dd class="text-right font-bold">
                    {{ formatDateTime(receipt.order.pickup_at) }}
                </dd>
            </template>
            <dt>Cashier</dt>
            <dd class="text-right">
                {{ receipt.order.cashier }}
                <template v-if="receipt.order.terminal">
                    · {{ receipt.order.terminal }}</template
                >
            </dd>
        </dl>

        <table class="mt-2 w-full border-t border-dashed border-black">
            <caption class="sr-only">
                Items
            </caption>
            <thead class="sr-only">
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Amount</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="(item, i) in receipt.items" :key="i">
                    <tr>
                        <td class="pt-1 align-top">
                            {{ formatQuantity(item.quantity) }} × {{ item.name
                            }}<template v-if="item.variant">
                                ({{ item.variant }})</template
                            >
                        </td>
                        <td class="pt-1 text-right align-top whitespace-nowrap">
                            {{ formatMoney(item.line_total) }}
                        </td>
                    </tr>
                    <tr v-for="(option, j) in item.options" :key="`${i}-${j}`">
                        <td class="pl-3" colspan="2">
                            + {{ option.name }}
                            <template v-if="Number(option.price_delta) !== 0"
                                >({{
                                    formatMoney(option.price_delta)
                                }})</template
                            >
                        </td>
                    </tr>
                    <tr v-if="Number(item.quantity) !== 1">
                        <td class="pl-3" colspan="2">
                            @ {{ formatMoney(item.unit_price) }}
                        </td>
                    </tr>
                    <tr v-if="item.notes">
                        <td class="pl-3 italic" colspan="2">
                            “{{ item.notes }}”
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <dl
            class="mt-2 grid grid-cols-[1fr_auto] gap-x-2 border-t border-dashed border-black pt-1"
        >
            <dt>Subtotal</dt>
            <dd class="text-right">
                {{ formatMoney(receipt.order.subtotal) }}
            </dd>
            <template v-if="Number(receipt.order.discount_total) > 0">
                <dt>Discount</dt>
                <dd class="text-right">
                    −{{ formatMoney(receipt.order.discount_total) }}
                </dd>
            </template>
            <dt class="text-sm font-bold">TOTAL</dt>
            <dd class="text-right text-sm font-bold">
                {{ formatMoney(receipt.order.grand_total) }}
            </dd>
        </dl>

        <dl
            v-for="(payment, i) in receipt.payments"
            :key="i"
            class="mt-1 grid grid-cols-[1fr_auto] gap-x-2"
        >
            <dt>{{ payment.method }}</dt>
            <dd class="text-right">{{ formatMoney(payment.amount) }}</dd>
            <template v-if="payment.amount_tendered !== null">
                <dt>Cash received</dt>
                <dd class="text-right">
                    {{ formatMoney(payment.amount_tendered) }}
                </dd>
                <dt>Change</dt>
                <dd class="text-right">
                    {{ formatMoney(payment.change_amount) }}
                </dd>
            </template>
            <template v-if="payment.reference_number">
                <dt>Reference</dt>
                <dd class="text-right">{{ payment.reference_number }}</dd>
            </template>
            <template v-if="payment.status !== 'verified'">
                <dt>Status</dt>
                <dd class="text-right">
                    {{ paymentStatus[payment.status] ?? payment.status }}
                </dd>
            </template>
        </dl>

        <p
            v-if="receipt.order.notes"
            class="mt-2 border-t border-dashed border-black pt-1"
        >
            Note: {{ receipt.order.notes }}
        </p>

        <footer
            class="mt-3 border-t border-dashed border-black pt-2 text-center"
        >
            <p v-if="receipt.store.footer">{{ receipt.store.footer }}</p>
            <p class="mt-1">This is not an official receipt.</p>
        </footer>
    </article>
</template>

<style>
@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }

    html,
    body {
        background: #fff !important;
    }

    .receipt {
        width: 72mm;
        margin: 0 auto;
    }
}
</style>
