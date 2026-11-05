<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PhasePlaceholder from '@/components/PhasePlaceholder.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Dashboard
            </h1>
            <p class="text-sm text-muted-foreground">
                Owner and admin overview.
            </p>
        </header>

        <PhasePlaceholder
            title="Sales and stock overview"
            phase="Phase 10"
            description="Today's sales, order count, average order value and low-stock alerts appear here once checkout, inventory and reporting are built. No figures are shown until they come from real transactions."
        />
    </div>
</template>
