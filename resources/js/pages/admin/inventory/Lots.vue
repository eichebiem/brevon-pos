<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDate, formatDateTime, formatQuantity } from '@/lib/format';
import { lots as lotsRoute } from '@/routes/admin/inventory';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    lot_number: string | null;
    received_at: string | null;
    expires_at: string | null;
    quantity_received: string;
    quantity_remaining: string;
    supplier: string | null;
    state: 'expired' | 'expiring' | 'used' | 'ok';
};

defineProps<{
    site: { id: number; code: string; name: string } | null;
    show: 'expiring' | 'expired' | 'all';
    expiringDays: number;
    counts: { expiring: number; expired: number };
    lots: Paginated<Row>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Lots & expiry', href: lotsRoute() }] },
});

const tabs = [
    { value: 'expiring', label: 'Expiring soon' },
    { value: 'expired', label: 'Expired' },
    { value: 'all', label: 'All lots' },
] as const;

const stateBadge = {
    expired: { tone: 'danger', label: 'Expired' },
    expiring: { tone: 'warning', label: 'Expiring soon' },
    used: { tone: 'neutral', label: 'Used up' },
    ok: { tone: 'success', label: 'In date' },
} as const;

function go(show: string): void {
    router.get(
        lotsRoute().url,
        { show },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Lots & expiry" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Lots &amp; expiry
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="site"
                    >{{ site.code }} · {{ site.name }}. </template
                >Lots come from deliveries of items set to record lot and
                expiry. What is left is an estimate: stock is assumed to be used
                first-expiring-first. Write off expired stock with Record waste.
            </p>
        </header>

        <nav class="flex flex-wrap gap-2" aria-label="Which lots">
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm"
                :class="
                    show === tab.value
                        ? 'border-brevon-navy bg-brevon-navy text-white'
                        : 'border-border bg-card'
                "
                :aria-current="show === tab.value ? 'page' : undefined"
                @click="go(tab.value)"
            >
                {{ tab.label
                }}<template v-if="tab.value === 'expiring'">
                    ({{ counts.expiring }})</template
                ><template v-if="tab.value === 'expired'">
                    ({{ counts.expired }})</template
                >
            </button>
        </nav>
        <p
            v-if="show === 'expiring'"
            class="-mt-3 text-xs text-muted-foreground"
        >
            Lots with stock left that expire within {{ expiringDays }} days.
        </p>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Lots
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">Lot</th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Expires
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Received
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Left (est.)
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="lots.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No lots to show.
                        </td>
                    </tr>
                    <tr
                        v-for="row in lots.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ row.lot_number ?? '—' }}
                            <p class="text-xs text-muted-foreground">
                                {{ row.supplier ?? 'No supplier' }} ·
                                {{ formatDateTime(row.received_at) }}
                            </p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDate(row.expires_at) }}
                            <StatusBadge
                                :tone="stateBadge[row.state].tone"
                                class="ml-1"
                                :data-test="`lot-state-${row.id}`"
                                >{{ stateBadge[row.state].label }}</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.quantity_received) }}
                            {{ row.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(row.quantity_remaining) }}
                            {{ row.unit }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="lots" noun="lots" />
    </div>
</template>
