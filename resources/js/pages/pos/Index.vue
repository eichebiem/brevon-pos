<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Timer } from '@lucide/vue';
import { computed } from 'vue';
import PhasePlaceholder from '@/components/PhasePlaceholder.vue';
import { Button } from '@/components/ui/button';
import { shift as shiftPage } from '@/routes/staff';

defineProps<{ canOpenShift: boolean }>();

const activeShift = computed(() => usePage().props.activeShift);
</script>

<template>
    <Head title="POS" />

    <div class="flex flex-1 flex-col items-center justify-center gap-6 p-6">
        <section
            v-if="!activeShift"
            class="flex w-full max-w-xl flex-col items-center gap-3 rounded-2xl border border-border bg-card p-8 text-center"
            aria-labelledby="no-shift-heading"
        >
            <h2
                id="no-shift-heading"
                class="font-display text-2xl text-brevon-navy dark:text-brevon-cream"
            >
                Open a shift to start selling
            </h2>
            <p class="text-sm text-muted-foreground">
                Choose your terminal and count the opening cash. Sales are
                recorded at the terminal's location.
            </p>
            <Button v-if="canOpenShift" size="lg" as-child>
                <Link :href="shiftPage()"
                    ><Timer aria-hidden="true" /> Open shift</Link
                >
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                Your role cannot open shifts. Ask a manager.
            </p>
        </section>

        <PhasePlaceholder
            title="Cashier checkout"
            phase="Phase 4b/4c"
            description="Product tiles, category tabs, search, the cart and Cash / GCash checkout come next. No sales can be recorded on this screen yet."
        />
    </div>
</template>
