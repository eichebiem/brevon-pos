<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatQuantity } from '@/lib/format';
import { create, index, show } from '@/routes/admin/recipes';

type Row = {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    at_sale: boolean;
    recipe: { version: number; makes: string; unit: string } | null;
    sizes: { id: number; name: string; version: number | null }[];
};

const props = defineProps<{
    rows: Row[];
    filters: { search?: string; missing?: boolean | string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Recipes', href: index() }] },
});

const form = reactive({
    search: props.filters.search ?? '',
    missing: Boolean(Number(props.filters.missing ?? 0)),
});

function apply(): void {
    router.get(
        index().url,
        {
            ...(form.search ? { search: form.search } : {}),
            ...(form.missing ? { missing: 1 } : {}),
        },
        { preserveState: true, replace: true },
    );
}

const hasAny = (row: Row) =>
    row.recipe !== null || row.sizes.some((s) => s.version !== null);
</script>

<template>
    <Head title="Recipes" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Recipes
            </h1>
            <p class="text-sm text-muted-foreground">
                Doughnut recipes say what a batch uses; ingredients come out of
                stock when the batch is posted. Drink recipes are per serving
                (for every size or one size) and come out of stock at each sale.
                Saving a change creates a new version; older versions stay for
                history.
            </p>
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
            <label class="flex h-9 items-center gap-2 text-sm">
                <input
                    v-model="form.missing"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                Without a recipe only
            </label>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Products and their recipes
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Product
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Used
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Recipe
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No products need a recipe. Doughnuts and drinks set
                            to "Deduct recipe ingredients at sale" appear here.
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.sku }} · {{ row.category }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{
                                row.at_sale
                                    ? 'At each sale'
                                    : 'When a batch is posted'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="row.recipe">
                                Version {{ row.recipe.version }}
                                <span
                                    v-if="!row.at_sale"
                                    class="text-xs text-muted-foreground"
                                    >· makes
                                    {{ formatQuantity(row.recipe.makes) }}
                                    {{ row.recipe.unit }}</span
                                >
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >· every size</span
                                >
                            </template>
                            <p
                                v-for="s in row.sizes.filter(
                                    (s) => s.version !== null,
                                )"
                                :key="s.id"
                                class="text-xs"
                            >
                                {{ s.name }}: version {{ s.version }}
                            </p>
                            <StatusBadge
                                v-if="!hasAny(row)"
                                tone="warning"
                                :data-test="`no-recipe-${row.sku}`"
                                >No recipe</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                v-if="hasAny(row)"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="show(row.id)">Versions</Link>
                            </Button>
                            <Button size="sm" class="ml-2" as-child>
                                <Link :href="create(row.id)">{{
                                    row.recipe ? 'Change' : 'Add recipe'
                                }}</Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
