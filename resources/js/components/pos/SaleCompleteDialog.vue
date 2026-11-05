<script setup lang="ts">
import { CheckCircle2, Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMoney } from '@/lib/format';
import { receipt } from '@/routes/pos/orders';
import type { LastOrder } from '@/types/pos';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ order: LastOrder | null }>();

function printReceipt(): void {
    if (props.order) {
        // Opened from a click, so pop-up blockers allow it. Printing never affects the sale.
        window.open(
            receipt(props.order.id, { query: { auto: 1 } }).url,
            '_blank',
            'noopener',
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="order"
            class="sm:max-w-md"
            data-test="pos-sale-complete"
        >
            <DialogHeader class="items-center text-center">
                <CheckCircle2
                    class="size-12 text-emerald-600"
                    aria-hidden="true"
                />
                <DialogTitle class="font-display text-2xl"
                    >Sale complete</DialogTitle
                >
                <DialogDescription>
                    Order
                    <strong class="font-mono text-foreground">{{
                        order.order_number
                    }}</strong>
                    · {{ formatMoney(order.grand_total) }} by
                    {{ order.payment_method }}
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="order.amount_tendered !== null"
                class="rounded-xl bg-brevon-navy px-4 py-5 text-center text-brevon-cream"
            >
                <span class="block text-sm text-brevon-gold-light"
                    >Give change</span
                >
                <strong
                    class="font-display text-5xl"
                    data-test="pos-complete-change"
                    >{{ formatMoney(order.change_amount) }}</strong
                >
                <span class="mt-1 block text-xs opacity-80"
                    >Received {{ formatMoney(order.amount_tendered) }}</span
                >
            </p>
            <p
                v-else-if="order.payment_status === 'pending_verification'"
                class="rounded-lg bg-amber-100 px-3 py-2 text-center text-sm text-amber-900"
            >
                {{ order.payment_method }} ref
                <strong class="font-mono">{{ order.reference_number }}</strong>
                is waiting for verification.
            </p>
            <p
                v-if="order.status === 'confirmed'"
                class="text-center text-sm text-muted-foreground"
            >
                Sent for preparation.
            </p>

            <DialogFooter class="gap-2 sm:justify-center">
                <Button
                    variant="outline"
                    size="lg"
                    class="min-h-12"
                    @click="printReceipt"
                    ><Printer aria-hidden="true" /> Print receipt</Button
                >
                <Button v-focus size="lg" class="min-h-12" @click="open = false"
                    >New sale</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
