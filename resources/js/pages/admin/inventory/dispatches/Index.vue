<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Truck } from '@lucide/vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatQuantity } from '@/lib/format';
import { create, index, show } from '@/routes/admin/inventory/dispatches';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    number: string;
    to: { code: string; name: string } | null;
    posted_at: string;
    by: string | null;
    lines: number;
    pieces: string;
};

defineProps<{ dispatches: Paginated<Row>; canDispatch: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dispatches', href: index() }] },
});
</script>

<template>
    <Head title="Dispatches" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Dispatches
                </h1>
                <p class="text-sm text-muted-foreground">
                    Doughnuts sent from the main branch to pop-ups. Stock moves
                    as soon as a dispatch is saved.
                </p>
            </div>
            <Button v-if="canDispatch" as-child>
                <Link :href="create()"
                    ><Truck aria-hidden="true" /> New dispatch</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Dispatches
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Number
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">To</th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Pieces
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Sent
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="dispatches.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            Nothing has been dispatched yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in dispatches.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(row.id)"
                                class="font-medium whitespace-nowrap text-brevon-navy underline-offset-4 hover:underline dark:text-brevon-cream"
                                >{{ row.number }}</Link
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ row.to?.code }} · {{ row.to?.name }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.pieces) }}
                            <span class="block text-xs text-muted-foreground"
                                >{{ row.lines }}
                                {{ row.lines === 1 ? 'item' : 'items' }}</span
                            >
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.posted_at) }}
                            <span class="block text-xs text-muted-foreground">{{
                                row.by
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="dispatches" noun="dispatches" />
    </div>
</template>
