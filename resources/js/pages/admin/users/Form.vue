<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { index, store, update } from '@/routes/admin/users';

type Location = {
    id: number;
    code: string;
    name: string;
    type: 'permanent' | 'pop_up';
    is_active: boolean;
};

type EmployeeProfile = {
    employee_code: string;
    first_name: string;
    last_name: string;
    middle_name: string | null;
    phone: string | null;
    hire_date: string | null;
};

type EditableUser = {
    id: number;
    is_self: boolean;
    last_login_at: string | null;
    name: string;
    username: string | null;
    email: string;
    is_active: boolean;
    roles: string[];
    branch_ids: number[];
    default_branch_id: number | null;
    employee: EmployeeProfile | null;
};

const props = defineProps<{
    user: EditableUser | null;
    roles: string[];
    locations: Location[];
}>();

const editing = computed(() => props.user !== null);
const title = computed(() =>
    props.user ? `Edit ${props.user.name}` : 'New user',
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

// Optional fields are edited as strings; the server converts '' to null.
const emptyProfile = () => ({
    employee_code: '',
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    hire_date: '',
});

const form = useForm({
    name: props.user?.name ?? '',
    username: props.user?.username ?? '',
    email: props.user?.email ?? '',
    password: '',
    password_confirmation: '',
    is_active: props.user?.is_active ?? true,
    roles: [...(props.user?.roles ?? [])],
    branch_ids: [...(props.user?.branch_ids ?? [])],
    default_branch_id: props.user?.default_branch_id ?? null,
    has_employee_profile: props.user?.employee != null,
    employee: props.user?.employee
        ? {
              ...props.user.employee,
              middle_name: props.user.employee.middle_name ?? '',
              phone: props.user.employee.phone ?? '',
              hire_date: props.user.employee.hire_date ?? '',
          }
        : emptyProfile(),
});

// Keep the default location valid when assignments change.
watch(
    () => [...form.branch_ids],
    (ids) => {
        if (
            form.default_branch_id === null ||
            !ids.includes(form.default_branch_id)
        ) {
            form.default_branch_id = ids[0] ?? null;
        }
    },
);

function toggle(list: (string | number)[], value: string | number): void {
    const position = list.indexOf(value);
    if (position === -1) {
        list.push(value);
    } else {
        list.splice(position, 1);
    }
}

function submit(): void {
    const options = { preserveScroll: true };
    if (props.user) {
        form.put(update(props.user.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <Head :title="title" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ title }}
                </h1>
                <p v-if="user" class="text-sm text-muted-foreground">
                    Last login: {{ formatDateTime(user.last_login_at) }}
                </p>
            </div>
        </header>

        <p
            v-if="user?.is_self"
            class="rounded-lg border border-border bg-secondary p-3 text-sm"
            role="note"
        >
            This is your own account. You cannot change your own roles or
            deactivate yourself.
        </p>

        <section
            class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-2"
            aria-labelledby="account-heading"
        >
            <h2 id="account-heading" class="font-semibold md:col-span-2">
                Account
            </h2>
            <div class="grid gap-2">
                <Label for="name">Full name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    v-model="form.username"
                    required
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    aria-describedby="username-hint"
                />
                <p id="username-hint" class="text-xs text-muted-foreground">
                    Used to sign in. Lower-case letters, numbers, dots, dashes
                    and underscores.
                </p>
                <InputError :message="form.errors.username" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email (for password resets)</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.email" />
            </div>
            <div class="flex items-center gap-3 self-end pb-2">
                <input
                    id="is_active"
                    v-model="form.is_active"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                    :disabled="user?.is_self"
                />
                <Label for="is_active">Account is active</Label>
                <InputError :message="form.errors.is_active" />
            </div>
            <div class="grid gap-2">
                <Label for="password">{{
                    editing ? 'New password (leave blank to keep)' : 'Password'
                }}</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    :required="!editing"
                />
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                />
            </div>
            <p
                v-if="editing"
                class="text-xs text-muted-foreground md:col-span-2"
            >
                Setting a new password or deactivating the account signs the
                user out of every device.
            </p>
        </section>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="roles-heading"
        >
            <h2 id="roles-heading" class="font-semibold">Roles</h2>
            <p class="text-xs text-muted-foreground">
                You can only assign roles whose permissions you hold yourself.
            </p>
            <fieldset class="flex flex-wrap gap-3" :disabled="user?.is_self">
                <legend class="sr-only">Roles</legend>
                <label
                    v-for="role in roles"
                    :key="role"
                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-border px-3 has-[:checked]:border-primary has-[:checked]:bg-secondary"
                >
                    <input
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        :checked="form.roles.includes(role)"
                        @change="toggle(form.roles, role)"
                    />
                    {{ role }}
                </label>
            </fieldset>
            <InputError :message="form.errors.roles" />
        </section>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="locations-heading"
        >
            <h2 id="locations-heading" class="font-semibold">
                Operating locations
            </h2>
            <p class="text-xs text-muted-foreground">
                Choose where this person may work, and their default location.
            </p>
            <div
                v-if="locations.length === 0"
                class="text-sm text-muted-foreground"
            >
                No locations exist yet.
            </div>
            <div
                v-for="location in locations"
                :key="location.id"
                class="flex flex-wrap items-center gap-4 rounded-lg border border-border px-3 py-2"
            >
                <label
                    class="flex min-h-9 flex-1 cursor-pointer items-center gap-2"
                >
                    <input
                        type="checkbox"
                        class="size-4 accent-brevon-navy"
                        :checked="form.branch_ids.includes(location.id)"
                        @change="toggle(form.branch_ids, location.id)"
                    />
                    <span>
                        <span class="font-medium">{{ location.code }}</span> ·
                        {{ location.name }}
                        <span class="text-xs text-muted-foreground">
                            ({{
                                location.type === 'pop_up'
                                    ? 'pop-up'
                                    : 'permanent'
                            }}{{ location.is_active ? '' : ', inactive' }})
                        </span>
                    </span>
                </label>
                <label
                    class="flex items-center gap-2 text-sm"
                    :class="{
                        'opacity-50': !form.branch_ids.includes(location.id),
                    }"
                >
                    <input
                        v-model="form.default_branch_id"
                        type="radio"
                        name="default_branch_id"
                        class="size-4 accent-brevon-navy"
                        :value="location.id"
                        :disabled="!form.branch_ids.includes(location.id)"
                    />
                    Default
                </label>
            </div>
            <InputError :message="form.errors.branch_ids" />
            <InputError :message="form.errors.default_branch_id" />
        </section>

        <section
            class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-2"
            aria-labelledby="employee-heading"
        >
            <div class="flex items-center gap-3 md:col-span-2">
                <input
                    id="has_employee_profile"
                    v-model="form.has_employee_profile"
                    type="checkbox"
                    class="size-4 accent-brevon-navy"
                />
                <Label id="employee-heading" for="has_employee_profile"
                    >Employee profile</Label
                >
            </div>
            <template v-if="form.has_employee_profile">
                <div class="grid gap-2">
                    <Label for="employee_code">Employee code</Label>
                    <Input
                        id="employee_code"
                        v-model="form.employee.employee_code"
                    />
                    <InputError
                        :message="fieldError('employee.employee_code')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="hire_date">Hire date</Label>
                    <Input
                        id="hire_date"
                        v-model="form.employee.hire_date"
                        type="date"
                    />
                    <InputError :message="fieldError('employee.hire_date')" />
                </div>
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input id="first_name" v-model="form.employee.first_name" />
                    <InputError :message="fieldError('employee.first_name')" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input id="last_name" v-model="form.employee.last_name" />
                    <InputError :message="fieldError('employee.last_name')" />
                </div>
                <div class="grid gap-2">
                    <Label for="middle_name">Middle name</Label>
                    <Input
                        id="middle_name"
                        v-model="form.employee.middle_name"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        v-model="form.employee.phone"
                        type="tel"
                    />
                    <InputError :message="fieldError('employee.phone')" />
                </div>
            </template>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-user"
            >
                <Spinner v-if="form.processing" />
                {{ editing ? 'Save changes' : 'Create user' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
