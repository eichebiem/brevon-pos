<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/suppliers';

const props = defineProps<{
    supplier: {
        id: number;
        code: string;
        name: string;
        contact_person: string | null;
        phone: string | null;
        email: string | null;
        address: string | null;
        payment_terms: string | null;
        is_active: boolean;
    } | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Suppliers', href: index() }] },
});

const form = useForm({
    name: props.supplier?.name ?? '',
    contact_person: props.supplier?.contact_person ?? '',
    phone: props.supplier?.phone ?? '',
    email: props.supplier?.email ?? '',
    address: props.supplier?.address ?? '',
    payment_terms: props.supplier?.payment_terms ?? '',
    is_active: props.supplier?.is_active ?? true,
});

function submit(): void {
    if (props.supplier) {
        form.put(update(props.supplier.id).url);
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="supplier ? `Edit ${supplier.name}` : 'New supplier'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ supplier ? `Edit ${supplier.name}` : 'New supplier' }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="supplier"
                    >Code {{ supplier.code }} (never changes).</template
                >
                <template v-else
                    >A code (SUP001, SUP002, …) is given when you
                    save.</template
                >
            </p>
        </header>

        <section
            class="grid max-w-2xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2 sm:col-span-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" maxlength="150" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="contact_person">Contact person</Label>
                <Input
                    id="contact_person"
                    v-model="form.contact_person"
                    maxlength="120"
                />
                <InputError :message="form.errors.contact_person" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">Phone</Label>
                <Input
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    maxlength="30"
                />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    maxlength="150"
                />
                <InputError :message="form.errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="payment_terms">Payment terms</Label>
                <Input
                    id="payment_terms"
                    v-model="form.payment_terms"
                    maxlength="100"
                    placeholder="e.g. Cash on delivery, 30 days"
                />
                <InputError :message="form.errors.payment_terms" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="address">Address</Label>
                <textarea
                    id="address"
                    v-model="form.address"
                    rows="2"
                    maxlength="500"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <InputError :message="form.errors.address" />
            </div>
            <label
                v-if="supplier"
                class="flex items-center gap-2 text-sm sm:col-span-2"
            >
                <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                Active (inactive suppliers cannot get new orders)
            </label>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-supplier"
            >
                <Spinner v-if="form.processing" /> Save
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
