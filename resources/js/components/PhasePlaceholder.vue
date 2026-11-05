<script setup lang="ts">
import { Construction } from '@lucide/vue';

defineProps<{
    title: string;
    phase: string;
    description: string;
}>();
</script>

<template>
    <section
        class="mx-auto flex max-w-xl flex-col items-center gap-4 rounded-2xl border border-dashed border-border bg-card p-10 text-center"
        aria-labelledby="phase-placeholder-title"
    >
        <span
            class="flex size-14 items-center justify-center rounded-full bg-brevon-gold text-brevon-navy"
        >
            <Construction class="size-7" aria-hidden="true" />
        </span>
        <h2
            id="phase-placeholder-title"
            class="font-display text-2xl text-brevon-navy dark:text-brevon-cream"
        >
            {{ title }}
        </h2>
        <p
            class="rounded-full bg-secondary px-3 py-1 text-xs font-semibold tracking-wide text-secondary-foreground uppercase"
        >
            Not implemented yet &middot; {{ phase }}
        </p>
        <p class="text-sm text-muted-foreground">{{ description }}</p>
    </section>
</template>
