<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Search, UserRoundCheck, UserRoundX } from '@lucide/vue';
import { reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import {
    create,
    edit,
    index,
    status as statusRoute,
} from '@/routes/admin/users';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    name: string;
    username: string | null;
    email: string;
    is_active: boolean;
    roles: string[];
    locations: string[];
    last_login_at: string | null;
};

const props = defineProps<{
    users: Paginated<Row>;
    filters: {
        search?: string;
        role?: string;
        status?: string;
        location?: number;
    };
    roles: string[];
    locations: { id: number; code: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const page = usePage();
const form = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
    location: props.filters.location ? String(props.filters.location) : '',
});

function applyFilters(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );
    router.get(index().url, query, { preserveState: true, replace: true });
}

function resetFilters(): void {
    Object.assign(form, { search: '', role: '', status: '', location: '' });
    applyFilters();
}

const pending = ref<Row | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function askToggle(row: Row): void {
    pending.value = row;
    confirmOpen.value = true;
}

function confirmToggle(): void {
    if (!pending.value) {
        return;
    }

    router.patch(
        statusRoute(pending.value.id).url,
        { is_active: !pending.value.is_active },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? 'The change was not saved.',
                ),
            onFinish: () => {
                processing.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

const selectClass =
    'h-9 rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring';
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Users
                </h1>
                <p class="text-sm text-muted-foreground">
                    Staff accounts, roles and location assignments. Accounts are
                    deactivated, never deleted.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()" data-test="create-user"
                    ><Plus aria-hidden="true" /> New user</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="applyFilters"
        >
            <div class="grid min-w-56 flex-1 gap-1">
                <label for="search" class="text-xs font-medium">Search</label>
                <div class="relative">
                    <Search
                        class="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="search"
                        v-model="form.search"
                        class="pl-8"
                        placeholder="Name, username or email"
                    />
                </div>
            </div>
            <div class="grid gap-1">
                <label for="role" class="text-xs font-medium">Role</label>
                <select id="role" v-model="form.role" :class="selectClass">
                    <option value="">All roles</option>
                    <option v-for="role in roles" :key="role" :value="role">
                        {{ role }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="form.status" :class="selectClass">
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="location" class="text-xs font-medium"
                    >Location</label
                >
                <select
                    id="location"
                    v-model="form.location"
                    :class="selectClass"
                >
                    <option value="">All locations</option>
                    <option
                        v-for="location in locations"
                        :key="location.id"
                        :value="String(location.id)"
                    >
                        {{ location.code }} · {{ location.name }}
                    </option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
            <Button type="button" variant="ghost" @click="resetFilters"
                >Reset</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Staff accounts
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Name
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Roles
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Locations
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Last login
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No users match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="row in users.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.username ? `@${row.username} · ` : ''
                                }}{{ row.email }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ row.roles.join(', ') }}</td>
                        <td class="px-4 py-3">
                            {{ row.locations.join(', ') || '—' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.last_login_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                :class="[
                                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    row.is_active
                                        ? 'bg-emerald-100 text-emerald-900'
                                        : 'bg-stone-200 text-stone-800',
                                ]"
                                >{{
                                    row.is_active ? 'Active' : 'Inactive'
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="edit(row.id)"
                                        ><Pencil aria-hidden="true" /> Edit<span
                                            class="sr-only"
                                        >
                                            {{ row.name }}</span
                                        ></Link
                                    >
                                </Button>
                                <Button
                                    v-if="row.id !== page.props.auth.user.id"
                                    variant="ghost"
                                    size="sm"
                                    :data-test="`toggle-user-${row.id}`"
                                    @click="askToggle(row)"
                                >
                                    <component
                                        :is="
                                            row.is_active
                                                ? UserRoundX
                                                : UserRoundCheck
                                        "
                                        aria-hidden="true"
                                    />
                                    {{
                                        row.is_active
                                            ? 'Deactivate'
                                            : 'Reactivate'
                                    }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="users" noun="users" />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                pending?.is_active
                    ? `Deactivate ${pending?.name}?`
                    : `Reactivate ${pending?.name}?`
            "
            :description="
                pending?.is_active
                    ? 'They will be signed out immediately and cannot log in until reactivated. Their history is kept.'
                    : 'They will be able to log in again with their existing password.'
            "
            :confirm-label="pending?.is_active ? 'Deactivate' : 'Reactivate'"
            :destructive="pending?.is_active"
            :processing="processing"
            @confirm="confirmToggle"
        />
    </div>
</template>
