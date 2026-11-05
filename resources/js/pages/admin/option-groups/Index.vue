<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, edit, index } from '@/routes/admin/option-groups';

type Row = {
    id: number;
    name: string;
    selection_type: 'single' | 'multiple';
    min_selections: number;
    max_selections: number | null;
    is_active: boolean;
    options_count: number;
    products_count: number;
};

defineProps<{ groups: Row[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Options & add-ons', href: index() }] },
});

const { can } = usePermissions();

function rule(row: Row): string {
    if (row.selection_type === 'single') {
        return row.min_selections ? 'Pick exactly one' : 'Pick one (optional)';
    }

    const max = row.max_selections ?? 'any';

    return `Pick ${row.min_selections}–${max}`;
}
</script>

<template>
    <Head title="Options & add-ons" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Options &amp; add-ons
                </h1>
                <p class="text-sm text-muted-foreground">
                    Choices offered with a product, e.g. size, milk or extra
                    shot. Attach groups to products on the product form.
                </p>
            </div>
            <Button v-if="can('products.create')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New option group</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Option groups
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Group
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Rule
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Options
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Used by
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
                    <tr v-if="groups.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No option groups yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in groups"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium">{{ row.name }}</td>
                        <td class="px-4 py-3">{{ rule(row) }}</td>
                        <td class="px-4 py-3">{{ row.options_count }}</td>
                        <td class="px-4 py-3">
                            {{ row.products_count }}
                            {{
                                row.products_count === 1
                                    ? 'product'
                                    : 'products'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="row.is_active ? 'success' : 'neutral'"
                                >{{
                                    row.is_active ? 'Active' : 'Inactive'
                                }}</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="can('products.update')"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <Link :href="edit(row.id)"
                                    ><Pencil aria-hidden="true" /> Edit<span
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
