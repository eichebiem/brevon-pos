<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { formatQuantity, selectClass } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/inventory/items';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    sku: string;
    name: string;
    type: string;
    unit: string | null;
    reorder_level: string;
    is_perishable: boolean;
    is_active: boolean;
    product: string | null;
};

const props = defineProps<{
    items: Paginated<Row>;
    filters: { search?: string; type?: string };
    types: { value: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock items', href: index() }] },
});

const { can } = usePermissions();
const form = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
});

function apply(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

const typeLabel = (value: string) =>
    props.types.find((t) => t.value === value)?.label ?? value;
</script>

<template>
    <Head title="Stock items" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Stock items
                </h1>
                <p class="text-sm text-muted-foreground">
                    Everything kept in stock. Doughnut items come from their
                    product; add ingredients, packaging and retail items here.
                    Set a reorder level to get low-stock alerts.
                </p>
            </div>
            <Button v-if="can('inventory.adjust')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New stock item</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium"
                    >Name or SKU</label
                >
                <Input id="search" v-model="form.search" />
            </div>
            <div class="grid gap-1">
                <label for="type" class="text-xs font-medium">Type</label>
                <select id="type" v-model="form.type" :class="selectClass">
                    <option value="">All types</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Stock items
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Type
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Reorder level
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Tracking
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="items.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No stock items match.
                        </td>
                    </tr>
                    <tr
                        v-for="row in items.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">
                                {{ row.name }}
                                <StatusBadge
                                    v-if="!row.is_active"
                                    tone="neutral"
                                    class="ml-1"
                                    >Inactive</StatusBadge
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.sku
                                }}<template v-if="row.product">
                                    · product {{ row.product }}</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ typeLabel(row.type) }}</td>
                        <td
                            class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                        >
                            <template v-if="Number(row.reorder_level) > 0"
                                >{{ formatQuantity(row.reorder_level) }}
                                {{ row.unit }}</template
                            >
                            <span v-else class="text-muted-foreground"
                                >No alert</span
                            >
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            <template v-if="row.is_perishable"
                                >Perishable</template
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="can('inventory.adjust')"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="edit(row.id)">Edit</Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="items" noun="items" />
    </div>
</template>
