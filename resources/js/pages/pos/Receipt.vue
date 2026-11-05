<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import type { Receipt } from '@/types/pos';

const props = defineProps<{ receipt: Receipt; autoPrint: boolean }>();

// Opened right after a sale with ?auto=1: print straight away. A print
// failure or a cancelled dialog never affects the sale (invariant 14).
function print(): void {
    window.print();
}

onMounted(() => {
    if (props.autoPrint) {
        setTimeout(print, 300);
    }
});
</script>

<template>
    <Head :title="`Receipt ${receipt.order.order_number}`" />

    <div class="min-h-svh bg-neutral-200 py-6 print:bg-white print:py-0">
        <p class="mb-4 text-center text-sm text-neutral-700 print:hidden">
            <button
                type="button"
                class="rounded-md bg-brevon-navy px-4 py-2 font-medium text-brevon-cream"
                @click="print"
            >
                Print
            </button>
            <span class="ml-3"
                >80 mm receipt printer · close this tab when done</span
            >
        </p>
        <div class="mx-auto w-fit bg-white shadow print:shadow-none">
            <ReceiptView :receipt="receipt" />
        </div>
    </div>
</template>
