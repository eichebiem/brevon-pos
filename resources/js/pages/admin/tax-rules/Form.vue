<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/tax-rules';

const props = defineProps<{
    rule: {
        id: number;
        name: string;
        code: string | null;
        rate: string;
        inclusive: boolean;
        effective_from: string;
        effective_until: string | null;
        is_active: boolean;
    } | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Tax rules', href: index() }] },
});

const form = useForm({
    name: props.rule?.name ?? '',
    code: props.rule?.code ?? '',
    rate: props.rule?.rate ?? '',
    inclusive: props.rule?.inclusive ?? true,
    effective_from: props.rule?.effective_from ?? '',
    effective_until: props.rule?.effective_until ?? '',
    is_active: props.rule?.is_active ?? false,
});

function submit(): void {
    if (props.rule) {
        form.put(update(props.rule.id).url);
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="rule ? `Edit ${rule.name}` : 'New tax rule'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ rule ? `Edit ${rule.name}` : 'New tax rule' }}
            </h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Every rule that is on and within its dates applies to each sale
                after discounts. Past sales keep the tax they were charged.
            </p>
        </header>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    maxlength="100"
                    placeholder="e.g. VAT"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="code">Code (optional)</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    maxlength="40"
                    class="uppercase"
                />
                <InputError :message="form.errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="rate">Rate (%)</Label>
                <Input
                    id="rate"
                    v-model="form.rate"
                    inputmode="decimal"
                    autocomplete="off"
                />
                <InputError :message="form.errors.rate" />
            </div>
            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">Prices</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.inclusive"
                        type="radio"
                        :value="true"
                        class="size-4 accent-brevon-navy"
                    />
                    Already include this tax
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.inclusive"
                        type="radio"
                        :value="false"
                        class="size-4 accent-brevon-navy"
                    />
                    Tax is added on top
                </label>
            </fieldset>
            <div class="grid gap-2">
                <Label for="effective_from">From</Label>
                <Input
                    id="effective_from"
                    v-model="form.effective_from"
                    type="date"
                />
                <InputError :message="form.errors.effective_from" />
            </div>
            <div class="grid gap-2">
                <Label for="effective_until">Until (optional)</Label>
                <Input
                    id="effective_until"
                    v-model="form.effective_until"
                    type="date"
                />
                <InputError :message="form.errors.effective_until" />
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                On (charge this tax on sales within its dates)
            </label>
        </section>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" /> Save
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
