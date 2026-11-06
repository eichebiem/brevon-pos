<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { selectClass } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/suppliers';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    code: string;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    payment_terms: string | null;
    is_active: boolean;
};

const props = defineProps<{
    suppliers: Paginated<Row>;
    filters: { search?: string; status?: string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Suppliers', href: index() }] },
});

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

function apply(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Suppliers" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Suppliers
                </h1>
                <p class="text-sm text-muted-foreground">
                    Who the shop buys from. Suppliers are switched off, never
                    deleted, so old orders keep their name.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New supplier</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium"
                    >Name or code</label
                >
                <Input id="search" v-model="form.search" />
            </div>
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="form.status" :class="selectClass">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Suppliers
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Supplier
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Contact
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Payment terms
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="suppliers.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No suppliers yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in suppliers.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">
                                {{ row.name }}
                                <StatusBadge
                                    v-if="!row.is_active"
                                    tone="neutral"
                                    class="ml-1"
                                    >Inactive</StatusBadge
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.code }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ row.contact_person ?? '—' }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    [row.phone, row.email]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ row.payment_terms ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button size="sm" variant="outline" as-child>
                                <Link :href="edit(row.id)">Edit</Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="suppliers" noun="suppliers" />
    </div>
</template>
