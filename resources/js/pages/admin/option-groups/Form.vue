<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, store, update } from '@/routes/admin/option-groups';

type Option = {
    id: number | null;
    name: string;
    price_delta: string;
    is_active: boolean;
};

const props = defineProps<{
    group: {
        id: number;
        name: string;
        selection_type: 'single' | 'multiple';
        min_selections: number;
        max_selections: number | null;
        is_active: boolean;
        options: Option[];
    } | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Options & add-ons', href: index() }] },
});

const form = useForm({
    name: props.group?.name ?? '',
    selection_type: props.group?.selection_type ?? 'single',
    min_selections: props.group?.min_selections ?? 0,
    max_selections: props.group?.max_selections ?? '',
    is_active: props.group?.is_active ?? true,
    options: (props.group?.options ?? [
        { id: null, name: '', price_delta: '0.00', is_active: true },
    ]) as Option[],
});

function addOption(): void {
    form.options.push({
        id: null,
        name: '',
        price_delta: '0.00',
        is_active: true,
    });
}

function removeNew(position: number): void {
    form.options.splice(position, 1);
}

function submit(): void {
    if (props.group) {
        form.put(update(props.group.id).url);
    } else {
        form.post(store().url);
    }
}

const optionError = (i: number, field: string): string | undefined =>
    (form.errors as Record<string, string>)[`options.${i}.${field}`];
</script>

<template>
    <Head :title="group ? `Edit ${group.name}` : 'New option group'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <h1
            class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
        >
            {{ group ? `Edit ${group.name}` : 'New option group' }}
        </h1>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 md:grid-cols-2"
        >
            <div class="grid gap-2 md:col-span-2">
                <Label for="name">Group name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="100"
                    placeholder="e.g. Size, Milk, Extra shot"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="type">Customer picks</Label>
                <select
                    id="type"
                    v-model="form.selection_type"
                    :class="selectClass"
                >
                    <option value="single">One option</option>
                    <option value="multiple">Several options</option>
                </select>
            </div>
            <div class="flex gap-4">
                <div class="grid gap-2">
                    <Label for="min">Minimum</Label>
                    <Input
                        id="min"
                        v-model.number="form.min_selections"
                        type="number"
                        min="0"
                        :max="form.selection_type === 'single' ? 1 : 20"
                        class="w-24"
                    />
                </div>
                <div
                    v-if="form.selection_type === 'multiple'"
                    class="grid gap-2"
                >
                    <Label for="max">Maximum</Label>
                    <Input
                        id="max"
                        v-model="form.max_selections"
                        type="number"
                        min="1"
                        max="20"
                        class="w-24"
                        placeholder="Any"
                    />
                </div>
            </div>
            <p class="text-xs text-muted-foreground md:col-span-2">
                A minimum of 1 makes the choice required at the POS.
            </p>
            <InputError
                class="md:col-span-2"
                :message="
                    form.errors.min_selections || form.errors.max_selections
                "
            />
            <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                Group is active
            </label>
        </section>

        <section
            class="grid max-w-3xl gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="options-heading"
        >
            <h2 id="options-heading" class="font-semibold">Options</h2>
            <p class="text-xs text-muted-foreground">
                Extra price is added to the product price (0 for free choices).
                Saved options can be made inactive but not removed, so past
                orders keep them.
            </p>
            <InputError :message="form.errors.options" />

            <div
                v-for="(option, i) in form.options"
                :key="option.id ?? `new-${i}`"
                class="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_9rem_auto_auto] sm:items-end"
            >
                <div class="grid gap-1">
                    <Label :for="`opt-name-${i}`">Name</Label>
                    <Input
                        :id="`opt-name-${i}`"
                        v-model="option.name"
                        required
                        maxlength="100"
                    />
                    <InputError :message="optionError(i, 'name')" />
                </div>
                <div class="grid gap-1">
                    <Label :for="`opt-price-${i}`">Extra price (₱)</Label>
                    <Input
                        :id="`opt-price-${i}`"
                        v-model="option.price_delta"
                        inputmode="decimal"
                        placeholder="0.00"
                    />
                    <InputError :message="optionError(i, 'price_delta')" />
                </div>
                <label class="flex min-h-9 items-center gap-2 text-sm">
                    <input
                        v-model="option.is_active"
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                    />
                    Active
                </label>
                <Button
                    v-if="option.id === null && form.options.length > 1"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="removeNew(i)"
                    >Remove</Button
                >
            </div>

            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addOption"
                    ><Plus aria-hidden="true" /> Add option</Button
                >
            </div>
        </section>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ group ? 'Save group' : 'Create group' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
