<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/locations';

type Row = {
    id: number;
    code: string;
    name: string;
    type: 'permanent' | 'pop_up';
    city: string | null;
    status: 'operating' | 'scheduled' | 'ended' | 'inactive';
    operating_starts_on: string | null;
    operating_ends_on: string | null;
    terminals_count: number;
    users_count: number;
    products_count: number;
};

defineProps<{ locations: Row[]; today: string }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: index() }] },
});

const statusLabel = {
    operating: ['Operating', 'success'],
    scheduled: ['Scheduled', 'info'],
    ended: ['Period ended', 'warning'],
    inactive: ['Inactive', 'neutral'],
} as const;

function period(row: Row): string {
    if (row.type === 'permanent') {
        return 'Always open';
    }

    if (!row.operating_starts_on && !row.operating_ends_on) {
        return 'No set dates';
    }

    return `${formatDate(row.operating_starts_on)} – ${row.operating_ends_on ? formatDate(row.operating_ends_on) : 'open-ended'}`;
}
</script>

<template>
    <Head title="Locations" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Locations
                </h1>
                <p class="text-sm text-muted-foreground">
                    The main branch and pop-up stands. Locations are
                    deactivated, never deleted, so their sales history is kept.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New pop-up</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Operating locations
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Location
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Type
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Operating period
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Terminals
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Products
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in locations"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.code
                                }}<template v-if="row.city">
                                    · {{ row.city }}</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{
                                row.type === 'permanent'
                                    ? 'Main branch'
                                    : 'Pop-up'
                            }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ period(row) }}
                        </td>
                        <td class="px-4 py-3">{{ row.terminals_count }}</td>
                        <td class="px-4 py-3">{{ row.products_count }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="statusLabel[row.status][1]">{{
                                statusLabel[row.status][0]
                            }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="edit(row.id)"
                                    ><Pencil aria-hidden="true" /> Manage<span
                                        class="sr-only"
                                    >
                                        {{ row.name }}</span
                                    ></Link
                                >
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
