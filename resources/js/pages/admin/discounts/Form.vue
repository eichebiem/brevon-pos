<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, store, update } from '@/routes/admin/discounts';

type Choice = { value: string; label: string };

const props = defineProps<{
    discount: {
        id: number;
        code: string | null;
        name: string;
        senior_pwd: boolean;
        discount_type: string;
        value: string;
        scope: string;
        product_id: number | null;
        category_id: number | null;
        starts_at: string | null;
        ends_at: string | null;
        max_uses: number | null;
        requires_approval: boolean;
        is_active: boolean;
    } | null;
    types: Choice[];
    scopes: Choice[];
    products: { id: number; name: string; sku: string }[];
    categories: { id: number; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Discounts', href: index() }] },
});

const seniorPwd = props.discount?.senior_pwd ?? false;

const form = useForm({
    code: props.discount?.code ?? '',
    name: props.discount?.name ?? '',
    discount_type: props.discount?.discount_type ?? 'percentage',
    value: props.discount?.value ?? '',
    scope: props.discount?.scope ?? 'order',
    product_id: props.discount?.product_id
        ? String(props.discount.product_id)
        : '',
    category_id: props.discount?.category_id
        ? String(props.discount.category_id)
        : '',
    starts_at: props.discount?.starts_at ?? '',
    ends_at: props.discount?.ends_at ?? '',
    max_uses:
        props.discount?.max_uses != null ? String(props.discount.max_uses) : '',
    requires_approval: props.discount?.requires_approval ?? false,
    is_active: props.discount?.is_active ?? true,
});

function submit(): void {
    if (props.discount) {
        form.put(update(props.discount.id).url);
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="discount ? `Edit ${discount.name}` : 'New discount'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ discount ? `Edit ${discount.name}` : 'New discount' }}
            </h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Past sales keep the discount as it was when they were made.
            </p>
        </header>

        <section
            v-if="seniorPwd"
            class="max-w-3xl rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
            role="note"
        >
            <p class="font-semibold">Senior Citizen / PWD</p>
            <p>
                Records the beneficiary's full name, ID card number and address
                on the order (shown masked except to owners). The owner set the
                rate at 12% with VAT disregarded.
            </p>
        </section>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    maxlength="100"
                    data-test="discount-name"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div v-if="!seniorPwd" class="grid gap-2">
                <Label for="code">Code (optional)</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    maxlength="30"
                    class="uppercase"
                    placeholder="e.g. STAFF20"
                />
                <InputError :message="form.errors.code" />
            </div>
            <div v-if="!seniorPwd" class="grid gap-2">
                <Label for="discount_type">Type</Label>
                <select
                    id="discount_type"
                    v-model="form.discount_type"
                    :class="[selectClass, 'h-10']"
                >
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <InputError :message="form.errors.discount_type" />
            </div>
            <div class="grid gap-2">
                <Label for="value">{{
                    form.discount_type === 'percentage'
                        ? 'Percent off'
                        : 'Amount off (₱)'
                }}</Label>
                <Input
                    id="value"
                    v-model="form.value"
                    inputmode="decimal"
                    autocomplete="off"
                    data-test="discount-value"
                />
                <InputError :message="form.errors.value" />
            </div>
            <template v-if="!seniorPwd">
                <div class="grid gap-2">
                    <Label for="scope">Applies to</Label>
                    <select
                        id="scope"
                        v-model="form.scope"
                        :class="[selectClass, 'h-10']"
                    >
                        <option
                            v-for="s in scopes"
                            :key="s.value"
                            :value="s.value"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.scope" />
                </div>
                <div v-if="form.scope === 'product'" class="grid gap-2">
                    <Label for="product_id">Product</Label>
                    <select
                        id="product_id"
                        v-model="form.product_id"
                        :class="[selectClass, 'h-10']"
                    >
                        <option value="" disabled>Choose a product</option>
                        <option
                            v-for="p in products"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }} ({{ p.sku }})
                        </option>
                    </select>
                    <InputError :message="form.errors.product_id" />
                </div>
                <div v-if="form.scope === 'category'" class="grid gap-2">
                    <Label for="category_id">Category</Label>
                    <select
                        id="category_id"
                        v-model="form.category_id"
                        :class="[selectClass, 'h-10']"
                    >
                        <option value="" disabled>Choose a category</option>
                        <option
                            v-for="c in categories"
                            :key="c.id"
                            :value="String(c.id)"
                        >
                            {{ c.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.category_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="starts_at">Starts (optional)</Label>
                    <Input
                        id="starts_at"
                        v-model="form.starts_at"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.starts_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="ends_at">Ends (optional)</Label>
                    <Input
                        id="ends_at"
                        v-model="form.ends_at"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.ends_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_uses">Number of orders (optional)</Label>
                    <Input
                        id="max_uses"
                        v-model="form.max_uses"
                        inputmode="numeric"
                        placeholder="No limit"
                    />
                    <InputError :message="form.errors.max_uses" />
                </div>
            </template>
            <div class="grid gap-3 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.requires_approval"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                    />
                    Always needs a manager's approval (otherwise only above the
                    amount in Store settings)
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        data-test="discount-active"
                    />
                    On (the counter can use it)
                </label>
            </div>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-discount"
            >
                <Spinner v-if="form.processing" /> Save
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
