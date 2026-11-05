<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, store, update } from '@/routes/admin/categories';

const props = defineProps<{
    category: {
        id: number;
        name: string;
        parent_id: number | null;
        description: string | null;
        sort_order: number;
        is_active: boolean;
    } | null;
    parents: { id: number; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Categories', href: index() }] },
});

const form = useForm({
    name: props.category?.name ?? '',
    parent_id: props.category?.parent_id
        ? String(props.category.parent_id)
        : '',
    description: props.category?.description ?? '',
    sort_order: props.category?.sort_order ?? 0,
});

function submit(): void {
    if (props.category) {
        form.put(update(props.category.id).url);
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="category ? `Edit ${category.name}` : 'New category'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <h1
            class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
        >
            {{ category ? `Edit ${category.name}` : 'New category' }}
        </h1>

        <section
            class="grid max-w-xl gap-5 rounded-xl border border-border bg-card p-5"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required maxlength="100" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="parent">Parent category (optional)</Label>
                <select
                    id="parent"
                    v-model="form.parent_id"
                    :class="selectClass"
                >
                    <option value="">None — top level</option>
                    <option
                        v-for="p in parents"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }}
                    </option>
                </select>
                <InputError :message="form.errors.parent_id" />
            </div>
            <div class="grid gap-2">
                <Label for="description">Description (optional)</Label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="2"
                    maxlength="1000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <InputError :message="form.errors.description" />
            </div>
            <div class="grid max-w-40 gap-2">
                <Label for="sort">Sort order</Label>
                <Input
                    id="sort"
                    v-model.number="form.sort_order"
                    type="number"
                    min="0"
                    max="9999"
                />
                <InputError :message="form.errors.sort_order" />
            </div>
            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ category ? 'Save category' : 'Create category' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </section>
    </form>
</template>
