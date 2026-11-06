<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { formatQuantity } from '@/lib/format';

export type StockFormItem = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    precision?: number;
    on_hand: string;
};

const props = defineProps<{
    items: StockFormItem[];
    errors: Record<string, string | undefined>;
    /** Column heading for the quantity typed in. */
    label: string;
    placeholder?: string;
    /** Prefix for input ids and data-test hooks. */
    name: string;
}>();

const lines = defineModel<{ inventory_item_id: number; quantity: string }[]>(
    'lines',
    { required: true },
);

const filter = ref('');
const visible = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return new Set(
        props.items
            .filter(
                (item) =>
                    term === '' ||
                    item.name.toLowerCase().includes(term) ||
                    item.sku.toLowerCase().includes(term),
            )
            .map((item) => item.id),
    );
});

const filled = computed(
    () => lines.value.filter((line) => line.quantity.trim() !== '').length,
);
</script>

<template>
    <div class="grid gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="filter"
                    class="w-64 pl-8"
                    :aria-label="`Find an item`"
                    placeholder="Find an item…"
                />
            </div>
            <p class="text-sm text-muted-foreground" role="status">
                {{ filled }} {{ filled === 1 ? 'item' : 'items' }} entered.
                Blank rows are skipped.
            </p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            On hand now
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            {{ label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="items.length === 0">
                        <td
                            colspan="3"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No items are stocked here.
                        </td>
                    </tr>
                    <tr
                        v-for="(item, i) in items"
                        v-show="visible.has(item.id)"
                        :key="item.id"
                        class="border-t border-border align-top"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ item.sku }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                        >
                            {{ formatQuantity(item.on_hand) }}
                            {{ item.unit }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Input
                                    :id="`${name}-${item.id}`"
                                    v-model="lines[i].quantity"
                                    class="w-28"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :aria-label="`${label} — ${item.name}`"
                                    :placeholder="placeholder"
                                    :data-test="`${name}-qty-${item.sku}`"
                                />
                                <span class="text-muted-foreground">{{
                                    item.unit
                                }}</span>
                            </div>
                            <InputError
                                :message="errors[`lines.${i}.quantity`]"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <InputError :message="errors.lines" />
    </div>
</template>
