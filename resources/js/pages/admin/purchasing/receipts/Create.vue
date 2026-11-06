<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatQuantity, selectClass } from '@/lib/format';
import { show as showOrder } from '@/routes/admin/purchase-orders';
import { index, store } from '@/routes/admin/receipts';
import type { PurchaseItem } from '@/types/purchasing';

type OrderLine = {
    purchase_order_item_id: number;
    inventory_item_id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    precision: number;
    track_lot: boolean;
    quantity_ordered: string;
    quantity_received: string;
    quantity_due: string;
    unit_cost: string;
};

type Line = {
    inventory_item_id: number;
    purchase_order_item_id: number | null;
    quantity: string;
    unit_cost: string;
    rejected_quantity: string;
    rejected_condition: string;
    lot_number: string;
    expires_at: string;
};

const props = defineProps<{
    order: {
        id: number;
        number: string;
        status_label: string;
        is_open: boolean;
        supplier: { supplier_code: string; name: string } | null;
        lines: OrderLine[];
    } | null;
    items: PurchaseItem[];
    suppliers: { id: number; supplier_code: string; name: string }[];
    canAcceptOver: boolean;
    conditions: { value: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] },
});

const blank = (id: number, orderLine: OrderLine | null): Line => ({
    inventory_item_id: id,
    purchase_order_item_id: orderLine?.purchase_order_item_id ?? null,
    quantity: '',
    unit_cost: orderLine?.unit_cost ?? '',
    rejected_quantity: '',
    rejected_condition: '',
    lot_number: '',
    expires_at: '',
});

const form = useForm({
    purchase_order_id: props.order?.id ?? null,
    supplier_id: '',
    delivery_reference: '',
    notes: '',
    accept_over: false,
    lines: (props.order?.lines ?? []).map((l) =>
        blank(l.inventory_item_id, l),
    ) as Line[],
});

type Meta = {
    sku: string | null;
    name: string | null;
    unit: string | null;
    track_lot: boolean;
    due: string | null;
};

const meta = computed(() => {
    const map = new Map<number, Meta>();

    for (const l of props.order?.lines ?? []) {
        map.set(l.inventory_item_id, {
            sku: l.sku,
            name: l.name,
            unit: l.unit,
            track_lot: l.track_lot,
            due: l.quantity_due,
        });
    }

    for (const i of props.items) {
        map.set(i.id, {
            sku: i.sku,
            name: i.name,
            unit: i.unit,
            track_lot: i.track_lot,
            due: null,
        });
    }

    return map;
});

function fillDue(): void {
    for (const line of form.lines) {
        const due = meta.value.get(line.inventory_item_id)?.due;

        if (due && Number(due) > 0) {
            line.quantity = due.includes('.')
                ? due.replace(/0+$/, '').replace(/\.$/, '')
                : due;
        }
    }
}

// Direct receipts: pick items one by one.
const available = computed(() =>
    props.items.filter(
        (i) => !form.lines.some((l) => l.inventory_item_id === i.id),
    ),
);
const picked = ref('');

function addLine(): void {
    if (picked.value) {
        form.lines.push(blank(Number(picked.value), null));
        picked.value = '';
    }
}

const errors = computed(() => form.errors as Record<string, string>);
const anyLot = computed(() =>
    form.lines.some((l) => meta.value.get(l.inventory_item_id)?.track_lot),
);

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <Head :title="order ? `Receive ${order.number}` : 'Receive a delivery'" />

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
                    order
                        ? `Receive delivery for ${order.number}`
                        : 'Receive a delivery'
                }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="order"
                    >From {{ order.supplier?.name }}. Enter what arrived in good
                    condition; leave a line empty if it did not come. Damaged or
                    expired goods are noted but not added to stock.</template
                >
                <template v-else
                    >For goods bought without a purchase order (for example a
                    market run). Stock is added at the main branch.</template
                >
            </p>
        </header>

        <p
            v-if="order && !order.is_open"
            class="max-w-3xl rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
        >
            This order is {{ order.status_label.toLowerCase() }}; nothing more
            can be received against it.
            <Link :href="showOrder(order.id)" class="underline"
                >Back to the order</Link
            >
        </p>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div v-if="!order" class="grid gap-2">
                <Label for="supplier_id">Supplier (optional)</Label>
                <select
                    id="supplier_id"
                    v-model="form.supplier_id"
                    :class="[selectClass, 'h-10']"
                >
                    <option value="">No supplier</option>
                    <option
                        v-for="s in suppliers"
                        :key="s.id"
                        :value="String(s.id)"
                    >
                        {{ s.name }} ({{ s.supplier_code }})
                    </option>
                </select>
                <InputError :message="errors.supplier_id" />
            </div>
            <div class="grid gap-2">
                <Label for="delivery_reference"
                    >Delivery receipt / invoice no. (optional)</Label
                >
                <Input
                    id="delivery_reference"
                    v-model="form.delivery_reference"
                    maxlength="100"
                />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">Notes</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="1000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <InputError :message="errors.notes" />
            </div>
            <label
                v-if="order && canAcceptOver"
                class="flex items-start gap-2 text-sm sm:col-span-2"
            >
                <input
                    v-model="form.accept_over"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-brevon-navy"
                    data-test="accept-over"
                />
                <span
                    >Accept more than ordered
                    <span class="block text-xs text-muted-foreground"
                        >Only if the extra is kept. Explain why in the
                        notes.</span
                    ></span
                >
            </label>
            <InputError class="sm:col-span-2" :message="errors.accept_over" />
        </section>

        <section
            class="grid gap-4 rounded-xl border border-border bg-card p-5"
            aria-labelledby="lines-heading"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="lines-heading" class="font-semibold">Items</h2>
                <Button
                    v-if="order"
                    type="button"
                    size="sm"
                    variant="outline"
                    data-test="fill-due"
                    @click="fillDue"
                    >Everything due arrived</Button
                >
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Item
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Received in good condition
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Cost per unit (₱)
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Damaged / expired
                            </th>
                            <th
                                v-if="anyLot"
                                scope="col"
                                class="py-2 pr-3 font-medium"
                            >
                                Lot no. / expiry
                            </th>
                            <th v-if="!order" scope="col">
                                <span class="sr-only">Remove</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="form.lines.length === 0">
                            <td
                                colspan="6"
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
                                    {{ meta.get(line.inventory_item_id)?.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ meta.get(line.inventory_item_id)?.sku
                                    }}<template
                                        v-if="
                                            meta.get(line.inventory_item_id)
                                                ?.due !== null
                                        "
                                    >
                                        · due
                                        {{
                                            formatQuantity(
                                                meta.get(line.inventory_item_id)
                                                    ?.due,
                                            )
                                        }}
                                        {{
                                            meta.get(line.inventory_item_id)
                                                ?.unit
                                        }}</template
                                    >
                                </p>
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex items-center gap-1">
                                    <Input
                                        v-model="line.quantity"
                                        class="w-24"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :aria-label="`Received of ${meta.get(line.inventory_item_id)?.name}`"
                                        :data-test="`gr-qty-${meta.get(line.inventory_item_id)?.sku}`"
                                    />
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            meta.get(line.inventory_item_id)
                                                ?.unit
                                        }}</span
                                    >
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
                                    :aria-label="`Cost of ${meta.get(line.inventory_item_id)?.name}`"
                                    :data-test="`gr-cost-${meta.get(line.inventory_item_id)?.sku}`"
                                />
                                <InputError
                                    :message="errors[`lines.${i}.unit_cost`]"
                                />
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex flex-wrap gap-1">
                                    <Input
                                        v-model="line.rejected_quantity"
                                        class="w-20"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :aria-label="`Damaged or expired quantity of ${meta.get(line.inventory_item_id)?.name}`"
                                    />
                                    <select
                                        v-model="line.rejected_condition"
                                        :class="[selectClass, 'h-9']"
                                        :aria-label="`Why ${meta.get(line.inventory_item_id)?.name} was refused`"
                                    >
                                        <option value="">Why?</option>
                                        <option
                                            v-for="c in conditions"
                                            :key="c.value"
                                            :value="c.value"
                                        >
                                            {{ c.label }}
                                        </option>
                                    </select>
                                </div>
                                <InputError
                                    :message="
                                        errors[
                                            `lines.${i}.rejected_quantity`
                                        ] ??
                                        errors[`lines.${i}.rejected_condition`]
                                    "
                                />
                            </td>
                            <td v-if="anyLot" class="py-2 pr-3">
                                <div
                                    v-if="
                                        meta.get(line.inventory_item_id)
                                            ?.track_lot
                                    "
                                    class="grid gap-1"
                                >
                                    <Input
                                        v-model="line.lot_number"
                                        class="w-32"
                                        maxlength="80"
                                        placeholder="Lot no."
                                        :aria-label="`Lot number of ${meta.get(line.inventory_item_id)?.name}`"
                                        :data-test="`gr-lot-${meta.get(line.inventory_item_id)?.sku}`"
                                    />
                                    <Input
                                        v-model="line.expires_at"
                                        class="w-36"
                                        type="date"
                                        :aria-label="`Expiry date of ${meta.get(line.inventory_item_id)?.name}`"
                                        :data-test="`gr-expiry-${meta.get(line.inventory_item_id)?.sku}`"
                                    />
                                    <InputError
                                        :message="
                                            errors[`lines.${i}.lot_number`] ??
                                            errors[`lines.${i}.expires_at`]
                                        "
                                    />
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >Not tracked</span
                                >
                            </td>
                            <td v-if="!order" class="py-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    :aria-label="`Remove ${meta.get(line.inventory_item_id)?.name}`"
                                    @click="form.lines.splice(i, 1)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <InputError :message="errors.lines ?? errors.order" />

            <div v-if="!order" class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1">
                    <Label for="add-item">Add an item</Label>
                    <select
                        id="add-item"
                        v-model="picked"
                        :class="[selectClass, 'h-10 min-w-64']"
                        data-test="gr-add-item"
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
                    data-test="gr-add-line"
                    @click="addLine"
                    ><Plus aria-hidden="true" /> Add</Button
                >
            </div>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="
                    form.processing || (order !== null && !order.is_open)
                "
                data-test="save-receipt"
            >
                <Spinner v-if="form.processing" /> Receive into stock
            </Button>
            <Button variant="outline" as-child>
                <Link :href="order ? showOrder(order.id) : index()"
                    >Cancel</Link
                >
            </Button>
        </div>
    </form>
</template>
