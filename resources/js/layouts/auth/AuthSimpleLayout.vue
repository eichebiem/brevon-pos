<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-brevon-cream p-6 md:p-10 dark:bg-background"
    >
        <div
            class="w-full max-w-sm rounded-[1.75rem] border border-border bg-card p-8 shadow-sm"
        >
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <AppLogoIcon class="size-28 ring-1 ring-brevon-gold/60" />
                    <div class="space-y-2 text-center">
                        <h1
                            class="font-display text-2xl text-brevon-navy dark:text-brevon-cream"
                        >
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Brevon Doughnuts &amp; Coffee &middot; Staff access only
        </p>
    </div>
</template>
