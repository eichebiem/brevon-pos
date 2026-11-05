<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PhasePlaceholder from '@/components/PhasePlaceholder.vue';
</script>

<template>
    <Head title="POS" />

    <div class="flex flex-1 items-center justify-center p-6">
        <PhasePlaceholder
            title="Cashier checkout"
            phase="Phase 4"
            description="Product tiles, category tabs, search, the cart and Cash / GCash checkout are built in Phase 4. No sales can be recorded on this screen yet."
        />
    </div>
</template>
