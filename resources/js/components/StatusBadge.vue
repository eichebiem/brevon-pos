<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    tone: 'success' | 'warning' | 'neutral' | 'danger' | 'info';
}>();

// Text colours meet 4.5:1 on their tinted backgrounds.
const classes = computed(
    () =>
        ({
            success: 'bg-emerald-100 text-emerald-900',
            warning: 'bg-amber-100 text-amber-900',
            neutral: 'bg-secondary text-secondary-foreground',
            danger: 'bg-red-100 text-red-900',
            info: 'bg-sky-100 text-sky-900',
        })[props.tone],
);
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
            classes,
        ]"
        ><slot
    /></span>
</template>
