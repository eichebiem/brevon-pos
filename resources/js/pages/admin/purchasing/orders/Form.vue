<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, selectClass } from '@/lib/format';
import { index, show, store, update } from '@/routes/admin/purchase-orders';
import { lineTotal, type PurchaseItem } from '@/types/purchasing';

type Line = { inventory_item_id: number; quantity: string; unit_cost: string };

const props = defineProps<{
    order: {
        id: number;
        number: string;
        supplier_id: number;
        expected_at: string | null;
        notes: string | null;
        lines: Line[];
    } | null;
    suppliers: { id: number; supplier_code: string; name: string }[];
    items: PurchaseItem[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] },
});

const form = useForm({
    supplier_id: props.order ? String(props.order.supplier_id) : '',
    expected_at: props.order?.expected_at ?? '',
    notes: props.order?.notes ?? '',
    lines: (props.order?.lines ?? []).map((l) => ({
        inventory_item_id: l.inventory_item_id,
        quantity: l.quantity,
        unit_cost: l.unit_cost,
    })) as Line[],
});

const byId = computed(() => new Map(props.items.map((i) => [i.id, i])));
const available = computed(() =>
    props.items.filter(
        (i) => !form.lines.some((l) => l.inventory_item_id === i.id),
    ),
);
const picked = ref('');

function addLine(): void {
    const id = Number(picked.value);

    if (!id) {
        return;
    }

    form.lines.push({ inventory_item_id: id, quantity: '', unit_cost: '' });
    picked.value = '';
}

const subtotal = computed(() =>
    form.lines.reduce(
        (sum, l) => sum + (lineTotal(l.quantity, l.unit_cost) ?? 0),
        0,
    ),
);

const errors = computed(() => form.errors as Record<string, string>);

function submit(): void {
    if (props.order) {
        form.put(update(props.order.id).url);
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="order ? `Edit ${order.number}` : 'New purchase order'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{
                    order ? `Edit draft ${order.number}` : 'New purchase order'
                }}
            </h1>
            <p class="text-sm text-muted-foreground">
                Saved as a draft for the main branch. Nothing is ordered until a
                manager approves it.
            </p>
        </header>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2">
                <Label for="supplier_id">Supplier</Label>
                <select
                    id="supplier_id"
                    v-model="form.supplier_id"
                    :class="[selectClass, 'h-10']"
                >
                    <option value="" disabled>Choose a supplier</option>
                    <option
                        v-for="s in suppliers"
                        :key="s.id"
                        :value="String(s.id)"
                    >
                        {{ s.name }} ({{ s.supplier_code }})
                    </option>
                </select>
                <p
                    v-if="suppliers.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    Add a supplier first.
                </p>
                <InputError :message="form.errors.supplier_id" />
            </div>
            <div class="grid gap-2">
                <Label for="expected_at">Expected delivery (optional)</Label>
                <Input
                    id="expected_at"
                    v-model="form.expected_at"
                    type="date"
                />
                <InputError :message="form.errors.expected_at" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">Notes (optional)</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="1000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
            </div>
        </section>

        <section
            class="grid max-w-3xl gap-4 rounded-xl border border-border bg-card p-5"
            aria-labelledby="lines-heading"
        >
            <h2 id="lines-heading" class="font-semibold">Items</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Item
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Quantity
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Expected cost per unit (₱)
                            </th>
                            <th
                                scope="col"
                                class="py-2 pr-3 text-right font-medium"
                            >
                                Line total
                            </th>
                            <th scope="col">
                                <span class="sr-only">Remove</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="form.lines.length === 0">
                            <td
                                colspan="5"
                                class="py-6 text-center text-muted-foreground"
                            >
                                No items yet. Pick one below.
                            </td>
                        </tr>
                        <tr
                            v-for="(line, i) in form.lines"
                            :key="line.inventory_item_id"
                            class="border-t border-border align-top"
                        >
                            <td class="py-2 pr-3">
                                <p class="font-medium">
                                    {{ byId.get(line.inventory_item_id)?.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ byId.get(line.inventory_item_id)?.sku }}
                                </p>
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex items-center gap-1">
                                    <Input
                                        v-model="line.quantity"
                                        class="w-24"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :aria-label="`Quantity of ${byId.get(line.inventory_item_id)?.name}`"
                                        :data-test="`po-qty-${byId.get(line.inventory_item_id)?.sku}`"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            byId.get(line.inventory_item_id)
                                                ?.unit
                                        }}
                                    </span>
                                </div>
                                <InputError
                                    :message="errors[`lines.${i}.quantity`]"
                                />
                            </td>
                            <td class="py-2 pr-3">
                                <Input
                                    v-model="line.unit_cost"
                                    class="w-28"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :aria-label="`Expected cost of ${byId.get(line.inventory_item_id)?.name}`"
                                    :data-test="`po-cost-${byId.get(line.inventory_item_id)?.sku}`"
                                />
                                <InputError
                                    :message="errors[`lines.${i}.unit_cost`]"
                                />
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{
                                    lineTotal(line.quantity, line.unit_cost) ===
                                    null
                                        ? '—'
                                        : formatMoney(
                                              String(
                                                  lineTotal(
                                                      line.quantity,
                                                      line.unit_cost,
                                                  ),
                                              ),
                                          )
                                }}
                            </td>
                            <td class="py-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    :aria-label="`Remove ${byId.get(line.inventory_item_id)?.name}`"
                                    @click="form.lines.splice(i, 1)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="form.lines.length">
                        <tr class="border-t border-border font-semibold">
                            <td colspan="3" class="py-2 pr-3 text-right">
                                Total
                            </td>
                            <td
                                class="py-2 pr-3 text-right tabular-nums"
                                data-test="po-subtotal"
                            >
                                {{ formatMoney(String(subtotal)) }}
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
            <InputError :message="errors.lines" />

            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1">
                    <Label for="add-item">Add an item</Label>
                    <select
                        id="add-item"
                        v-model="picked"
                        :class="[selectClass, 'h-10 min-w-64']"
                        data-test="po-add-item"
                    >
                        <option value="">Choose an item</option>
                        <option
                            v-for="item in available"
                            :key="item.id"
                            :value="String(item.id)"
                        >
                            {{ item.name }} ({{ item.sku }})
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!picked"
                    data-test="po-add-line"
                    @click="addLine"
                    ><Plus aria-hidden="true" /> Add</Button
                >
            </div>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                No stock items can be ordered yet. Add ingredients, packaging or
                retail items under Stock items.
            </p>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-po"
            >
                <Spinner v-if="form.processing" /> Save draft
            </Button>
            <Button variant="outline" as-child>
                <Link :href="order ? show(order.id) : index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
