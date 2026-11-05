<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { ref } from 'vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import { Button } from '@/components/ui/button';
import { index, receipt as receiptRoute, reprint } from '@/routes/pos/orders';
import type { Receipt } from '@/types/pos';

const props = defineProps<{ receipt: Receipt }>();
const printing = ref(false);

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
</script>

<template>
    <Head :title="`Order ${receipt.order.order_number}`" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Order {{ receipt.order.order_number }}
            </h1>
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

        <div
            class="mx-auto w-fit rounded-lg border border-border bg-white p-2 shadow-sm"
        >
            <ReceiptView :receipt="receipt" />
        </div>
    </div>
</template>
