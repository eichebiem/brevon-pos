<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Provisional logo until official vector assets are supplied (D-016). -->
    <img
        src="/images/brevon-logo.jpg"
        alt="Brevon Doughnuts & Coffee"
        :class="['rounded-full object-cover', className]"
        v-bind="$attrs"
    />
</template>
