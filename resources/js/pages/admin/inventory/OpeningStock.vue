<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { opening, stock } from '@/routes/admin/inventory';

type Item = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    precision: number;
};

const props = defineProps<{
    site: { id: number; code: string; name: string } | null;
    isPopUp: boolean;
    items: Item[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stock on hand', href: stock() },
            { title: 'Opening stock', href: opening() },
        ],
    },
});

const form = useForm({
    lines: props.items.map((item) => ({
        inventory_item_id: item.id,
        quantity: '',
        unit_cost: '',
    })),
});

function submit(): void {
    form.post(opening().url, { preserveScroll: true });
}

const error = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <Head title="Opening stock" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Opening stock
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="site"
                    >{{ site.code }} · {{ site.name }}. Enter what is physically
                    on hand now. Each item can only get an opening count once;
                    leave an item blank to skip it.</template
                >
                <template v-else>No operating location is selected.</template>
            </p>
        </header>

        <p
            v-if="isPopUp"
            class="rounded-lg border border-border bg-secondary p-3 text-sm"
            role="note"
        >
            Pop-up stands only stock finished doughnuts. Until deliveries from
            the main branch are recorded in the app, enter the doughnuts the
            stand received here; this does not reduce the main branch's stock.
        </p>

        <div
            v-if="items.length === 0"
            class="rounded-xl border border-border bg-card p-10 text-center text-sm text-muted-foreground"
        >
            Every item already has stock recorded at this location.
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-border bg-card"
        >
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Opening stock
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Quantity on hand
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Unit cost (₱, optional)
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, i) in items"
                        :key="item.id"
                        class="border-t border-border align-top"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ item.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Input
                                    :id="`qty-${item.id}`"
                                    v-model="form.lines[i].quantity"
                                    class="w-28"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :aria-label="`Quantity of ${item.name}`"
                                    :placeholder="
                                        item.precision === 0 ? '0' : '0.000'
                                    "
                                />
                                <span class="text-muted-foreground">{{
                                    item.unit
                                }}</span>
                            </div>
                            <InputError
                                :message="error(`lines.${i}.quantity`)"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <Input
                                :id="`cost-${item.id}`"
                                v-model="form.lines[i].unit_cost"
                                class="w-28"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-label="`Unit cost of ${item.name}`"
                                placeholder="0.00"
                            />
                            <InputError
                                :message="error(`lines.${i}.unit_cost`)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <InputError :message="error('lines')" />

        <div class="flex gap-3">
            <Button
                v-if="items.length"
                type="submit"
                :disabled="form.processing"
                data-test="save-opening-stock"
            >
                <Spinner v-if="form.processing" />
                Save opening stock
            </Button>
            <Button variant="outline" as-child>
                <Link :href="stock()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
