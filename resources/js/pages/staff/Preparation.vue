<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PhasePlaceholder from '@/components/PhasePlaceholder.vue';
</script>

<template>
    <Head title="Preparation" />

    <div class="flex flex-1 items-center justify-center p-6">
        <PhasePlaceholder
            title="Preparation queue"
            phase="Phase 5"
            description="Active orders grouped by status, with permission-controlled status updates, arrive in Phase 5."
        />
    </div>
</template>
