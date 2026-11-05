<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Tag } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, prices, store, update } from '@/routes/admin/products';

type Variant = {
    id: number | null;
    name: string;
    sku: string | null;
    is_default: boolean;
    is_active: boolean;
};

type TypeOption = {
    value: string;
    label: string;
    modes: { value: string; label: string }[];
};

const props = defineProps<{
    product: {
        id: number;
        category_id: number;
        sku: string;
        name: string;
        description: string | null;
        base_unit: string;
        is_active: boolean;
        is_available_for_pos: boolean;
        product_type: string;
        inventory_tracking_mode: string;
        image_url: string | null;
        stock_item: { sku: string; name: string; unit: string | null } | null;
        variants: Variant[];
        option_group_ids: number[];
        location_ids: number[];
    } | null;
    categories: { id: number; name: string; is_active: boolean }[];
    optionGroups: { id: number; name: string; is_active: boolean }[];
    locations: {
        id: number;
        code: string;
        name: string;
        type: 'permanent' | 'pop_up';
        is_active: boolean;
    }[];
    types: TypeOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Products', href: index() }] },
});

const main = props.locations.find((l) => l.type === 'permanent');

const form = useForm<{
    name: string;
    sku: string;
    category_id: string;
    description: string;
    product_type: string;
    inventory_tracking_mode: string;
    base_unit: string;
    is_available_for_pos: boolean;
    base_price: string;
    image: File | null;
    remove_image: boolean;
    variants: Variant[];
    option_group_ids: number[];
    location_ids: number[];
}>({
    name: props.product?.name ?? '',
    sku: props.product?.sku ?? '',
    category_id: props.product ? String(props.product.category_id) : '',
    description: props.product?.description ?? '',
    product_type: props.product?.product_type ?? 'finished_good',
    inventory_tracking_mode:
        props.product?.inventory_tracking_mode ?? 'finished_stock',
    base_unit: props.product?.base_unit ?? 'piece',
    is_available_for_pos: props.product?.is_available_for_pos ?? true,
    base_price: '',
    image: null,
    remove_image: false,
    variants: [...(props.product?.variants ?? [])],
    option_group_ids: [...(props.product?.option_group_ids ?? [])],
    location_ids: props.product
        ? [...props.product.location_ids]
        : main
          ? [main.id]
          : [],
});

const modes = computed(
    () => props.types.find((t) => t.value === form.product_type)?.modes ?? [],
);

// Keep the tracking mode valid for the chosen product type (D-085).
watch(
    () => form.product_type,
    () => {
        if (
            !modes.value.some((m) => m.value === form.inventory_tracking_mode)
        ) {
            form.inventory_tracking_mode = modes.value[0]?.value ?? '';
        }
    },
);

const finishedStock = computed(
    () => form.inventory_tracking_mode === 'finished_stock',
);

// Pop-ups only sell finished-stock items (D-063): drop them otherwise.
watch(finishedStock, (allowed) => {
    if (!allowed) {
        const popUps = props.locations
            .filter((l) => l.type === 'pop_up')
            .map((l) => l.id);
        form.location_ids = form.location_ids.filter(
            (id) => !popUps.includes(id),
        );
    }
});

const preview = ref<string | null>(null);
const shownImage = computed(() =>
    form.remove_image
        ? null
        : (preview.value ?? props.product?.image_url ?? null),
);

function pickImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    form.remove_image = false;
    preview.value = file ? URL.createObjectURL(file) : null;
}

function toggle(list: number[], id: number): void {
    const at = list.indexOf(id);
    if (at === -1) {
        list.push(id);
    } else {
        list.splice(at, 1);
    }
}

function addVariant(): void {
    form.variants.push({
        id: null,
        name: '',
        sku: '',
        is_default: form.variants.length === 0,
        is_active: true,
    });
}

function setDefault(position: number): void {
    form.variants.forEach((v, i) => (v.is_default = i === position));
}

function submit(): void {
    const options = { forceFormData: true, preserveScroll: true };

    if (props.product) {
        const product = props.product;
        form.transform(({ base_price: _price, ...data }) => ({
            ...data,
            _method: 'put',
        })).post(update(product.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

const error = (key: string): string | undefined =>
    (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head :title="product ? `Edit ${product.name}` : 'New product'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ product ? `Edit ${product.name}` : 'New product' }}
                </h1>
                <p
                    v-if="product && !product.is_active"
                    class="text-sm text-amber-800"
                >
                    This product is archived and not sold anywhere.
                </p>
            </div>
            <Button v-if="product" variant="outline" as-child>
                <Link :href="prices(product.id)"
                    ><Tag aria-hidden="true" /> Prices &amp; history</Link
                >
            </Button>
        </header>

        <section
            class="grid gap-5 rounded-xl border border-border bg-card p-5 md:grid-cols-2"
            aria-labelledby="basics"
        >
            <h2 id="basics" class="font-semibold md:col-span-2">Basics</h2>
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="sku">SKU</Label>
                <Input
                    id="sku"
                    v-model="form.sku"
                    required
                    maxlength="60"
                    class="uppercase"
                    placeholder="DN-GLAZED"
                />
                <InputError :message="form.errors.sku" />
            </div>
            <div class="grid gap-2">
                <Label for="category">Category</Label>
                <select
                    id="category"
                    v-model="form.category_id"
                    required
                    :class="selectClass"
                >
                    <option value="" disabled>Choose…</option>
                    <option
                        v-for="c in categories"
                        :key="c.id"
                        :value="String(c.id)"
                        :disabled="!c.is_active"
                    >
                        {{ c.name }}{{ c.is_active ? '' : ' (archived)' }}
                    </option>
                </select>
                <InputError :message="form.errors.category_id" />
            </div>
            <div v-if="!product" class="grid gap-2">
                <Label for="price">Selling price (₱)</Label>
                <Input
                    id="price"
                    v-model="form.base_price"
                    required
                    inputmode="decimal"
                    placeholder="45.00"
                    aria-describedby="price-help"
                />
                <p id="price-help" class="text-xs text-muted-foreground">
                    Applies at every location. Set location or variant prices
                    after saving.
                </p>
                <InputError
                    :message="form.errors.base_price || error('price')"
                />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="description">Description (optional)</Label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="2"
                    maxlength="2000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="image">Photo (optional)</Label>
                <div class="flex flex-wrap items-center gap-4">
                    <img
                        v-if="shownImage"
                        :src="shownImage"
                        alt="Product photo"
                        class="size-20 rounded-lg object-cover"
                    />
                    <div class="grid gap-2">
                        <input
                            id="image"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-card file:px-3 file:py-1.5"
                            @change="pickImage"
                        />
                        <label
                            v-if="product?.image_url"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="form.remove_image"
                                type="checkbox"
                                class="size-4 accent-brevon-navy"
                            />
                            Remove the current photo
                        </label>
                    </div>
                </div>
                <InputError :message="form.errors.image" />
            </div>
        </section>

        <section
            class="grid gap-5 rounded-xl border border-border bg-card p-5 md:grid-cols-2"
            aria-labelledby="stock"
        >
            <h2 id="stock" class="font-semibold md:col-span-2">
                Type and stock
            </h2>
            <div class="grid gap-2">
                <Label for="type">Product type</Label>
                <select
                    id="type"
                    v-model="form.product_type"
                    :class="selectClass"
                >
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="mode">Stock tracking</Label>
                <select
                    id="mode"
                    v-model="form.inventory_tracking_mode"
                    :class="selectClass"
                >
                    <option v-for="m in modes" :key="m.value" :value="m.value">
                        {{ m.label }}
                    </option>
                </select>
                <InputError :message="form.errors.inventory_tracking_mode" />
            </div>
            <p
                v-if="finishedStock"
                class="rounded-lg bg-secondary p-3 text-sm text-secondary-foreground md:col-span-2"
            >
                <template v-if="product?.stock_item">
                    Stock item: <strong>{{ product.stock_item.sku }}</strong> ·
                    {{ product.stock_item.name }} ({{
                        product.stock_item.unit ?? 'pc'
                    }}). Quantities are managed under Inventory.
                </template>
                <template v-else>
                    A stock item with this SKU is created automatically so sales
                    can deduct stock.
                </template>
            </p>
        </section>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="variants-heading"
        >
            <h2 id="variants-heading" class="font-semibold">
                Variants (optional)
            </h2>
            <p class="text-xs text-muted-foreground">
                For versions of the same product, e.g. Single and Box of 6. Each
                variant can have its own price on the Prices page. Saved
                variants can be made inactive but not removed.
            </p>
            <InputError :message="form.errors.variants" />

            <div
                v-for="(variant, i) in form.variants"
                :key="variant.id ?? `new-${i}`"
                class="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_12rem_auto_auto_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label :for="`v-name-${i}`">Name</Label>
                    <Input
                        :id="`v-name-${i}`"
                        v-model="variant.name"
                        required
                        maxlength="100"
                    />
                    <InputError :message="error(`variants.${i}.name`)" />
                </div>
                <div class="grid gap-1">
                    <Label :for="`v-sku-${i}`">SKU (optional)</Label>
                    <Input
                        :id="`v-sku-${i}`"
                        :model-value="variant.sku ?? ''"
                        class="uppercase"
                        maxlength="60"
                        @update:model-value="variant.sku = String($event)"
                    />
                    <InputError :message="error(`variants.${i}.sku`)" />
                </div>
                <label class="flex min-h-9 items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="default-variant"
                        class="size-4 accent-brevon-navy"
                        :checked="variant.is_default"
                        @change="setDefault(i)"
                    />
                    Default
                </label>
                <label class="flex min-h-9 items-center gap-2 text-sm">
                    <input
                        v-model="variant.is_active"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                    />
                    Active
                </label>
                <Button
                    v-if="variant.id === null"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="form.variants.splice(i, 1)"
                    >Remove</Button
                >
            </div>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addVariant"
                    ><Plus aria-hidden="true" /> Add variant</Button
                >
            </div>
        </section>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="options-heading"
        >
            <h2 id="options-heading" class="font-semibold">
                Options &amp; add-ons
            </h2>
            <p
                v-if="optionGroups.length === 0"
                class="text-sm text-muted-foreground"
            >
                No option groups exist yet.
            </p>
            <div class="flex flex-wrap gap-3">
                <label
                    v-for="g in optionGroups"
                    :key="g.id"
                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-border px-3 has-[:checked]:border-primary has-[:checked]:bg-secondary"
                >
                    <input
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        :checked="form.option_group_ids.includes(g.id)"
                        @change="toggle(form.option_group_ids, g.id)"
                    />
                    {{ g.name }}{{ g.is_active ? '' : ' (inactive)' }}
                </label>
            </div>
        </section>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="where-heading"
        >
            <h2 id="where-heading" class="font-semibold">Where it is sold</h2>
            <p class="text-xs text-muted-foreground">
                Pop-up stands only sell finished-stock items delivered from the
                main branch.
            </p>
            <InputError :message="form.errors.location_ids" />
            <div class="flex flex-wrap gap-3">
                <label
                    v-for="l in locations"
                    :key="l.id"
                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-border px-3 has-[:checked]:border-primary has-[:checked]:bg-secondary has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50"
                >
                    <input
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        :checked="form.location_ids.includes(l.id)"
                        :disabled="l.type === 'pop_up' && !finishedStock"
                        @change="toggle(form.location_ids, l.id)"
                    />
                    <span
                        ><span class="font-medium">{{ l.code }}</span> ·
                        {{ l.name }}{{ l.is_active ? '' : ' (inactive)' }}</span
                    >
                </label>
            </div>
            <label class="mt-2 flex items-center gap-2 text-sm">
                <input
                    v-model="form.is_available_for_pos"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                Show on the POS (untick to mark it temporarily unavailable)
            </label>
        </section>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ product ? 'Save product' : 'Create product' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
