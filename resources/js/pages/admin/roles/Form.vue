<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/roles';

const props = defineProps<{
    role: {
        id: number;
        name: string;
        permissions: string[];
        is_system: boolean;
        is_owner: boolean;
    } | null;
    groups: Record<string, string[]>;
    grantable: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Roles', href: index() }] },
});

const readOnly = computed(() => props.role?.is_owner === true);
const title = computed(() =>
    props.role ? `Role: ${props.role.name}` : 'New role',
);

const form = useForm({
    name: props.role?.name ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

const canGrant = (permission: string): boolean =>
    props.grantable.includes(permission) ||
    form.permissions.includes(permission);

function toggle(permission: string): void {
    const position = form.permissions.indexOf(permission);
    if (position === -1) {
        form.permissions.push(permission);
    } else {
        form.permissions.splice(position, 1);
    }
}

function submit(): void {
    if (props.role) {
        form.put(update(props.role.id).url, { preserveScroll: true });
    } else {
        form.post(store().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="title" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ title }}
            </h1>
            <p v-if="readOnly" class="text-sm text-muted-foreground">
                The Owner role always holds every permission and cannot be
                edited.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                You can only add permissions you hold yourself. Disabled boxes
                are permissions you cannot grant.
            </p>
        </header>

        <div class="grid max-w-md gap-2">
            <Label for="role-name">Role name</Label>
            <Input
                id="role-name"
                v-model="form.name"
                :disabled="readOnly || role?.is_system"
                required
            />
            <p
                v-if="role?.is_system && !readOnly"
                class="text-xs text-muted-foreground"
            >
                Built-in roles keep their name.
            </p>
            <InputError :message="form.errors.name" />
        </div>

        <InputError :message="form.errors.permissions" />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <fieldset
                v-for="(permissions, group) in groups"
                :key="group"
                class="rounded-xl border border-border bg-card p-4"
                :disabled="readOnly"
            >
                <legend class="px-1 text-sm font-semibold">{{ group }}</legend>
                <label
                    v-for="permission in permissions"
                    :key="permission"
                    class="flex min-h-9 items-center gap-2 text-sm"
                    :class="{ 'opacity-50': !canGrant(permission) }"
                >
                    <input
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        :checked="form.permissions.includes(permission)"
                        :disabled="!canGrant(permission)"
                        @change="toggle(permission)"
                    />
                    <code class="text-xs">{{ permission }}</code>
                </label>
            </fieldset>
        </div>

        <div class="flex gap-3">
            <Button v-if="!readOnly" type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ role ? 'Save role' : 'Create role' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">{{ readOnly ? 'Back' : 'Cancel' }}</Link>
            </Button>
        </div>
    </form>
</template>
