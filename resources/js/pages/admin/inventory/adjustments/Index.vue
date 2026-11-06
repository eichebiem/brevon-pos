<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardCheck, Diff } from '@lucide/vue';
import { ref } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, selectClass } from '@/lib/format';
import { create, index, show } from '@/routes/admin/inventory/adjustments';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    number: string;
    kind_label: string;
    status: 'pending' | 'posted' | 'rejected';
    status_label: string;
    reason: string;
    location: { code: string; name: string } | null;
    created_by: string | null;
    created_at: string;
    decided_by: string | null;
    items_count: number;
};

const props = defineProps<{
    adjustments: Paginated<Row>;
    filters: { status?: string };
    pendingCount: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Counts & adjustments', href: index() }] },
});

const { can } = usePermissions();
const status = ref(props.filters.status ?? '');

function apply(): void {
    router.get(index().url, status.value ? { status: status.value } : {}, {
        preserveState: true,
        replace: true,
    });
}

const tone = {
    pending: 'warning',
    posted: 'success',
    rejected: 'neutral',
} as const;
</script>

<template>
    <Head title="Counts & adjustments" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Counts &amp; adjustments
                </h1>
                <p class="text-sm text-muted-foreground">
                    Every correction to stock, with its reason and who approved
                    it. Posted ones are never edited.
                </p>
            </div>
            <div v-if="can('inventory.adjust')" class="flex flex-wrap gap-2">
                <Button as-child>
                    <Link :href="create({ query: { kind: 'count' } })"
                        ><ClipboardCheck aria-hidden="true" /> Count stock</Link
                    >
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="create({ query: { kind: 'change' } })"
                        ><Diff aria-hidden="true" /> Add or remove</Link
                    >
                </Button>
            </div>
        </header>

        <p
            v-if="pendingCount"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
            role="status"
        >
            {{ pendingCount }}
            {{ pendingCount === 1 ? 'adjustment is' : 'adjustments are' }}
            waiting for approval.
        </p>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="status" :class="selectClass">
                    <option value="">All</option>
                    <option value="pending">Waiting for approval</option>
                    <option value="posted">Posted</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Counts and adjustments
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Number
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Reason
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Entered
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="adjustments.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No counts or adjustments yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in adjustments.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(row.id)"
                                class="font-medium whitespace-nowrap text-brevon-navy underline-offset-4 hover:underline dark:text-brevon-cream"
                                >{{ row.number }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                {{ row.kind_label }} · {{ row.items_count }}
                                {{ row.items_count === 1 ? 'item' : 'items' }}
                                · {{ row.location?.code }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ row.reason }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.created_at) }}
                            <span class="block text-xs text-muted-foreground">{{
                                row.created_by
                            }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="tone[row.status]">{{
                                row.status_label
                            }}</StatusBadge>
                            <span
                                v-if="row.decided_by"
                                class="block pt-1 text-xs text-muted-foreground"
                                >{{ row.decided_by }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="adjustments" noun="adjustments" />
    </div>
</template>
