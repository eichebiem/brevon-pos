<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types/auth';

defineProps<{
    paginator: Paginated<unknown>;
    noun?: string;
}>();

function label(raw: string): string {
    return raw
        .replace('&laquo;', '‹')
        .replace('&raquo;', '›')
        .replace(/&[a-z]+;/g, '')
        .trim();
}
</script>

<template>
    <nav
        v-if="paginator.total > 0"
        class="flex flex-wrap items-center justify-between gap-3 text-sm"
        aria-label="Pagination"
    >
        <p class="text-muted-foreground">
            Showing {{ paginator.from }}–{{ paginator.to }} of
            {{ paginator.total }} {{ noun ?? 'records' }}
        </p>
        <ul v-if="paginator.last_page > 1" class="flex flex-wrap gap-1">
            <li v-for="(link, index) in paginator.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    :aria-current="link.active ? 'page' : undefined"
                    :class="[
                        'inline-flex min-h-9 min-w-9 items-center justify-center rounded-md border px-3',
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-card hover:bg-accent',
                    ]"
                    >{{ label(link.label) }}</Link
                >
                <span
                    v-else
                    class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-md px-3 text-muted-foreground"
                    aria-hidden="true"
                    >{{ label(link.label) }}</span
                >
            </li>
        </ul>
    </nav>
</template>
