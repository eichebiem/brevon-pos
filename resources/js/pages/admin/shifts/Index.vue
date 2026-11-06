<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatMoney, selectClass } from '@/lib/format';
import { index, show } from '@/routes/admin/shifts';
import type { Paginated } from '@/types/auth';
import type { ShiftRow } from '@/types/shifts';

const props = defineProps<{
    site: { id: number; code: string; name: string } | null;
    shifts: Paginated<ShiftRow>;
    filters: { status?: string; from?: string; to?: string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Shifts', href: index() }] },
});

const form = reactive({
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function applyFilters(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

function resetFilters(): void {
    Object.assign(form, { status: '', from: '', to: '' });
    applyFilters();
}

const varianceTone = (value: string | null) =>
    value === null || Number(value) === 0
        ? 'success'
        : Number(value) > 0
          ? 'info'
          : 'danger';
</script>

<template>
    <Head title="Shifts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Shifts
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="site"
                    >{{ site.code }} · {{ site.name }}. Open a shift to see its
                    cash, approve movements, review or correct it.</template
                >
                <template v-else>No operating location is selected.</template>
            </p>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="form.status" :class="selectClass">
                    <option value="">All shifts</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                    <option value="needs_review">Needs review</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="from" class="text-xs font-medium">From</label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="grid gap-1">
                <label for="to" class="text-xs font-medium">To</label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
            <Button type="submit">Apply</Button>
            <Button type="button" variant="ghost" @click="resetFilters"
                >Reset</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Shifts
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Shift
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Cashier
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Opened
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Expected
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Counted
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Difference
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="shifts.data.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No shifts match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="row in shifts.data"
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
                                {{ row.terminal }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ row.cashier }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.opened_at) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(row.expected_cash) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(row.counted_cash) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <StatusBadge
                                v-if="row.variance !== null"
                                :tone="varianceTone(row.variance)"
                                >{{ formatMoney(row.variance) }}</StatusBadge
                            >
                            <template v-else>—</template>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                v-if="row.status === 'open'"
                                tone="info"
                                >Open</StatusBadge
                            >
                            <StatusBadge
                                v-else-if="row.reviewed_at"
                                tone="success"
                                >Reviewed</StatusBadge
                            >
                            <StatusBadge
                                v-else-if="row.needs_review"
                                tone="warning"
                                >Needs review</StatusBadge
                            >
                            <StatusBadge v-else tone="neutral"
                                >Closed</StatusBadge
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="shifts" noun="shifts" />
    </div>
</template>
