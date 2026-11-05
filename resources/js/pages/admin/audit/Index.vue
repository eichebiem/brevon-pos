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
    event: string;
    entity: string;
    entity_id: number;
    user: string | null;
    location: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
};

const props = defineProps<{
    logs: Paginated<Row>;
    filters: { event?: string; entity?: string; from?: string; to?: string };
    entities: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audit log', href: index() }] },
});

const form = reactive({
    event: props.filters.event ?? '',
    entity: props.filters.entity ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

const show = (value: unknown): string =>
    Array.isArray(value)
        ? value.join(', ') || '—'
        : value === null || value === undefined || value === ''
          ? '—'
          : String(value);
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Audit log
                </h1>
                <p class="text-sm text-muted-foreground">
                    Read-only history of sensitive changes. Times are shown in
                    Asia/Manila.
                </p>
            </div>
            <Button variant="outline" as-child>
                <Link :href="logins()">Login history</Link>
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="apply"
        >
            <div class="grid gap-1">
                <label for="event" class="text-xs font-medium">Event</label>
                <Input
                    id="event"
                    v-model="form.event"
                    placeholder="e.g. user."
                />
            </div>
            <div class="grid gap-1">
                <label for="entity" class="text-xs font-medium"
                    >Record type</label
                >
                <select
                    id="entity"
                    v-model="form.entity"
                    class="h-9 rounded-md border border-input bg-card px-3 text-sm"
                >
                    <option value="">All</option>
                    <option
                        v-for="entity in entities"
                        :key="entity"
                        :value="entity"
                    >
                        {{ entity }}
                    </option>
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

        <ol class="grid gap-3" aria-label="Audit entries">
            <li
                v-if="logs.data.length === 0"
                class="rounded-xl border border-border bg-card p-10 text-center text-muted-foreground"
            >
                No audit entries match these filters.
            </li>
            <li
                v-for="log in logs.data"
                :key="log.id"
                class="rounded-xl border border-border bg-card p-4 text-sm"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <p>
                        <span class="font-semibold">{{ log.event }}</span>
                        <span class="text-muted-foreground">
                            · {{ log.entity }} #{{ log.entity_id }}</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatDateTime(log.created_at) }} ·
                        {{ log.user ?? 'System' }}
                        <template v-if="log.location">
                            · {{ log.location }}</template
                        >
                        <template v-if="log.ip_address">
                            · {{ log.ip_address }}</template
                        >
                    </p>
                </div>
                <dl
                    v-if="log.old_values || log.new_values"
                    class="mt-3 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr_1fr]"
                >
                    <template
                        v-for="key in Array.from(
                            new Set([
                                ...Object.keys(log.old_values ?? {}),
                                ...Object.keys(log.new_values ?? {}),
                            ]),
                        )"
                        :key="key"
                    >
                        <dt class="font-medium">{{ key }}</dt>
                        <dd
                            class="text-muted-foreground line-through decoration-muted-foreground/50"
                        >
                            <span class="sr-only">Before: </span
                            >{{ show(log.old_values?.[key]) }}
                        </dd>
                        <dd>
                            <span class="sr-only">After: </span
                            >{{ show(log.new_values?.[key]) }}
                        </dd>
                    </template>
                </dl>
            </li>
        </ol>

        <Pagination :paginator="logs" noun="entries" />
    </div>
</template>
