<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { index, logins } from '@/routes/admin/audit';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    created_at: string;
    identifier: string;
    user: string | null;
    succeeded: boolean;
    reason: string | null;
    ip_address: string | null;
};

const props = defineProps<{
    events: Paginated<Row>;
    filters: { search?: string; result?: string; from?: string; to?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Audit log', href: index() },
            { title: 'Login history', href: logins() },
        ],
    },
});

const form = reactive({
    search: props.filters.search ?? '',
    result: props.filters.result ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply(): void {
    router.get(
        logins().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

const reasons: Record<string, string> = {
    invalid_credentials: 'Wrong email/username or password',
    inactive: 'Account inactive',
    throttled: 'Too many attempts',
};
</script>

<template>
    <Head title="Login history" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Login history
                </h1>
                <p class="text-sm text-muted-foreground">
                    Successful and failed sign-in attempts (no passwords are
                    stored).
                </p>
            </div>
            <Button variant="outline" as-child>
                <Link :href="index()">Audit log</Link>
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium"
                    >Email or username</label
                >
                <Input id="search" v-model="form.search" />
            </div>
            <div class="grid gap-1">
                <label for="result" class="text-xs font-medium">Result</label>
                <select
                    id="result"
                    v-model="form.result"
                    class="h-9 rounded-md border border-input bg-card px-3 text-sm"
                >
                    <option value="">All</option>
                    <option value="success">Succeeded</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="from" class="text-xs font-medium">From</label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="grid gap-1">
                <label for="to" class="text-xs font-medium">To</label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Login attempts
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            When
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Entered
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Account
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Result
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="events.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No login attempts match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="event in events.data"
                        :key="event.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(event.created_at) }}
                        </td>
                        <td class="px-4 py-3">{{ event.identifier || '—' }}</td>
                        <td class="px-4 py-3">{{ event.user ?? 'Unknown' }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="[
                                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    event.succeeded
                                        ? 'bg-emerald-100 text-emerald-900'
                                        : 'bg-red-100 text-red-900',
                                ]"
                                >{{
                                    event.succeeded
                                        ? 'Succeeded'
                                        : (reasons[event.reason ?? ''] ??
                                          'Failed')
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-3">{{ event.ip_address ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="events" noun="attempts" />
    </div>
</template>
