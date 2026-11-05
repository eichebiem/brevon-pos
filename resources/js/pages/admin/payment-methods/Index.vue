<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, update } from '@/routes/admin/payment-methods';

type Method = {
    id: number;
    code: string;
    name: string;
    type: 'cash' | 'e_wallet' | 'card' | 'other';
    requires_reference: boolean;
    is_active: boolean;
};

const props = defineProps<{ methods: Method[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Payment methods', href: index() }] },
});

const typeLabel: Record<Method['type'], string> = {
    cash: 'Cash (counted in the drawer)',
    e_wallet: 'E-wallet (not in the drawer)',
    card: 'Card (not in the drawer)',
    other: 'Other (not in the drawer)',
};

const forms = props.methods.map((method) => ({
    method,
    form: useForm({
        name: method.name,
        is_active: method.is_active,
        requires_reference: method.requires_reference,
    }),
}));

// The badge shows what the server has saved (fresh props), not the unsaved form.
const savedActive = computed<Record<number, boolean>>(() =>
    Object.fromEntries(props.methods.map((m) => [m.id, m.is_active])),
);

function save(id: number): void {
    const entry = forms.find((f) => f.method.id === id);
    entry?.form.put(update(id).url, {
        preserveScroll: true,
        onSuccess: () => entry.form.defaults(),
    });
}
</script>

<template>
    <Head title="Payment methods" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Payment methods
            </h1>
            <p class="text-sm text-muted-foreground">
                What the POS can accept. Only cash counts toward the drawer.
                GCash payments are checked by hand against the GCash app.
            </p>
        </header>

        <div class="grid max-w-3xl gap-4">
            <form
                v-for="{ method, form } in forms"
                :key="method.id"
                class="grid gap-4 rounded-xl border border-border bg-card p-5"
                @submit.prevent="save(method.id)"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-semibold">
                            {{ method.name }}
                            <span
                                class="ml-1 font-mono text-xs text-muted-foreground"
                                >{{ method.code }}</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ typeLabel[method.type] }}
                        </p>
                    </div>
                    <StatusBadge
                        :tone="savedActive[method.id] ? 'success' : 'neutral'"
                        >{{
                            savedActive[method.id] ? 'Active' : 'Off'
                        }}</StatusBadge
                    >
                </div>

                <div class="grid gap-2 sm:max-w-sm">
                    <Label :for="`name-${method.id}`">Name on screen</Label>
                    <Input
                        :id="`name-${method.id}`"
                        v-model="form.name"
                        required
                        maxlength="80"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 accent-brevon-navy"
                        />
                        Accept at the POS
                    </label>
                    <label
                        v-if="method.type !== 'cash'"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.requires_reference"
                            type="checkbox"
                            class="size-4 accent-brevon-navy"
                        />
                        Require a reference number
                    </label>
                </div>
                <InputError :message="form.errors.is_active" />
                <InputError :message="form.errors.requires_reference" />

                <div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="form.processing || !form.isDirty"
                        >Save</Button
                    >
                </div>
            </form>
        </div>
    </div>
</template>
