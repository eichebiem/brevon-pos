<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatQuantity, selectClass } from '@/lib/format';
import { create, index, show } from '@/routes/admin/production';
import type { Paginated } from '@/types/auth';
import { batchTone, type BatchRow } from '@/types/production';

const props = defineProps<{
    batches: Paginated<BatchRow>;
    filters: { status?: string };
    draftCount: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Production', href: index() }] },
});

const { can } = usePermissions();
const status = ref(props.filters.status ?? '');

function apply(): void {
    router.get(index().url, status.value ? { status: status.value } : {}, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Production" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Production
                </h1>
                <p class="text-sm text-muted-foreground">
                    Batches of doughnuts made at the main branch.
                    <template v-if="draftCount"
                        >{{ draftCount }}
                        {{ draftCount === 1 ? 'draft is' : 'drafts are' }}
                        waiting to be posted.</template
                    >
                </p>
            </div>
            <Button v-if="can('production.create')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New batch</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="status" :class="selectClass">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="posted">Posted</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Production batches
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Batch
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Doughnut
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Planned
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Good
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Rejected
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="batches.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No batches yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in batches.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(row.id)"
                                class="font-medium underline-offset-2 hover:underline"
                                >{{ row.number }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                {{ row.created_by }} ·
                                {{ formatDateTime(row.created_at) }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ row.product?.name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.planned) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.actual) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.rejected) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="batchTone[row.status]">{{
                                row.status_label
                            }}</StatusBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="batches" noun="batches" />
    </div>
</template>
