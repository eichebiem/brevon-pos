<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, store, update } from '@/routes/admin/inventory/items';

const props = defineProps<{
    item: {
        id: number;
        sku: string;
        name: string;
        item_type: string;
        unit_of_measure_id: number;
        reorder_level: string;
        is_perishable: boolean;
        track_lot: boolean;
        is_active: boolean;
        product: string | null;
    } | null;
    linked: boolean;
    hasHistory: boolean;
    units: { id: number; code: string; name: string; precision: number }[];
    types: { value: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock items', href: index() }] },
});

const form = useForm({
    sku: props.item?.sku ?? '',
    name: props.item?.name ?? '',
    item_type: props.item?.item_type ?? 'ingredient',
    unit_of_measure_id: String(
        props.item?.unit_of_measure_id ?? props.units[0]?.id ?? '',
    ),
    reorder_level: props.item?.reorder_level ?? '0',
    is_perishable: props.item?.is_perishable ?? false,
    track_lot: props.item?.track_lot ?? false,
    is_active: props.item?.is_active ?? true,
});

function submit(): void {
    if (!props.item) {
        form.post(store().url);

        return;
    }
    // Linked doughnut items only change their stock settings (D-134).
    form.transform((data) =>
        props.linked
            ? {
                  reorder_level: data.reorder_level,
                  is_perishable: data.is_perishable,
                  track_lot: data.track_lot,
              }
            : data,
    ).put(update(props.item.id).url);
}
</script>

<template>
    <Head :title="item ? `Edit ${item.name}` : 'New stock item'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ item ? `Edit ${item.name}` : 'New stock item' }}
            </h1>
            <p v-if="linked" class="text-sm text-muted-foreground">
                This item belongs to the product {{ item?.product }}. Its name,
                SKU and unit come from the product; set its reorder level and
                tracking here.
            </p>
        </header>

        <section
            class="grid max-w-2xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2">
                <Label for="sku">SKU</Label>
                <Input
                    id="sku"
                    v-model="form.sku"
                    :disabled="!!item"
                    maxlength="60"
                    placeholder="e.g. ING-FLOUR"
                />
                <p v-if="item" class="text-xs text-muted-foreground">
                    SKUs never change.
                </p>
                <InputError :message="form.errors.sku" />
            </div>
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    :disabled="linked"
                    maxlength="150"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="item_type">Type</Label>
                <select
                    id="item_type"
                    v-model="form.item_type"
                    :disabled="!!item"
                    :class="[selectClass, 'h-10']"
                >
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <InputError :message="form.errors.item_type" />
            </div>
            <div class="grid gap-2">
                <Label for="unit">Unit</Label>
                <select
                    id="unit"
                    v-model="form.unit_of_measure_id"
                    :disabled="linked || hasHistory"
                    :class="[selectClass, 'h-10']"
                >
                    <option
                        v-for="u in units"
                        :key="u.id"
                        :value="String(u.id)"
                    >
                        {{ u.name }} ({{ u.code }})
                    </option>
                </select>
                <p
                    v-if="hasHistory && !linked"
                    class="text-xs text-muted-foreground"
                >
                    The unit is fixed once the item has stock history.
                </p>
                <InputError :message="form.errors.unit_of_measure_id" />
            </div>
            <div class="grid gap-2">
                <Label for="reorder_level">Reorder level</Label>
                <Input
                    id="reorder_level"
                    v-model="form.reorder_level"
                    inputmode="decimal"
                    autocomplete="off"
                    aria-describedby="reorder-help"
                />
                <p id="reorder-help" class="text-xs text-muted-foreground">
                    Shown as low stock at or below this. 0 turns the alert off.
                </p>
                <InputError :message="form.errors.reorder_level" />
            </div>
            <fieldset class="grid content-start gap-3 text-sm">
                <legend class="mb-2 font-medium">Tracking</legend>
                <label class="flex items-center gap-2">
                    <input
                        v-model="form.is_perishable"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                    />
                    Perishable
                </label>
                <label class="flex items-start gap-2">
                    <input
                        v-model="form.track_lot"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-brevon-navy"
                    />
                    <span
                        >Record lot and expiry when received
                        <span class="block text-xs text-muted-foreground"
                            >Receiving asks for a lot number or expiry
                            date.</span
                        ></span
                    >
                </label>
                <label v-if="item && !linked" class="flex items-center gap-2">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                    />
                    Active
                </label>
            </fieldset>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-item"
            >
                <Spinner v-if="form.processing" /> Save
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
