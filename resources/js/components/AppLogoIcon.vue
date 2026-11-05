<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

const page = usePage();

// Logo uploaded in Store settings; otherwise the provisional logo (D-016).
const src = computed(
    () => page.props.store?.logo_url ?? '/images/brevon-logo.jpg',
);
</script>

<template>
    <img
        :src="src"
        alt="Brevon Doughnuts & Coffee"
        :class="['rounded-full object-cover', className]"
        v-bind="$attrs"
    />
</template>
