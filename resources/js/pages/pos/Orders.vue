<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatMoney } from '@/lib/format';
import { index as posIndex } from '@/routes/pos';
import { index, show } from '@/routes/pos/orders';

type Row = {
    id: number;
    order_number: string;
    created_at: string;
    order_type: string;
    status: string;
    payment_status: string;
    grand_total: string;
    items_count: number;
    payment_method: string | null;
    cashier: string | null;
};

const props = defineProps<{
    site: { code: string; name: string } | null;
    hasShift: boolean;
    scope: 'shift' | 'today';
    search: string | null;
    orders: Row[];
}>();

const term = ref(props.search ?? '');

function load(scope: 'shift' | 'today'): void {
    router.get(
        index().url,
        { scope, search: term.value.trim() || undefined },
        { preserveState: true, replace: true },
    );
}

const statusTone: Record<
    string,
    ['success' | 'warning' | 'neutral' | 'danger' | 'info', string]
> = {
    completed: ['success', 'Completed'],
    confirmed: ['info', 'In preparation'],
    preparing: ['info', 'Preparing'],
    ready: ['info', 'Ready'],
    cancelled: ['danger', 'Cancelled'],
    refunded: ['neutral', 'Refunded'],
    pending: ['neutral', 'Pending'],
};
</script>

<template>
    <Head title="Orders" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Orders
                </h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="site"
                        >{{ site.code }} · {{ site.name }} ·
                    </template>
                    {{ scope === 'shift' ? 'This shift' : 'Today' }}
                </p>
            </div>
            <Button variant="outline" as-child class="min-h-11">
                <Link :href="posIndex()"
                    ><ArrowLeft aria-hidden="true" /> Back to sale</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-center gap-2"
            @submit.prevent="load(scope)"
        >
            <div
                v-if="hasShift"
                class="flex rounded-lg border border-border p-1"
                role="group"
                aria-label="Show"
            >
                <button
                    v-for="option in [
                        { v: 'shift', l: 'This shift' },
                        { v: 'today', l: 'Today' },
                    ] as const"
                    :key="option.v"
                    type="button"
                    :aria-pressed="scope === option.v"
                    :class="[
                        'min-h-9 rounded-md px-3 text-sm font-medium',
                        scope === option.v
                            ? 'bg-brevon-navy text-brevon-cream'
                            : 'hover:bg-secondary',
                    ]"
                    @click="load(option.v)"
                >
                    {{ option.l }}
                </button>
            </div>
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="term"
                    type="search"
                    placeholder="Order number"
                    aria-label="Search by order number"
                    class="h-11 w-56 pl-9"
                />
            </div>
            <Button type="submit" variant="outline" class="min-h-11"
                >Search</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Orders
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Order
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Time
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Type
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Payment
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="orders.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No orders yet.
                        </td>
                    </tr>
                    <tr
                        v-for="order in orders"
                        :key="order.id"
                        class="hover:bg-secondary/50"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(order.id)"
                                class="font-mono font-semibold text-brevon-navy underline-offset-2 hover:underline dark:text-brevon-gold-light"
                                >{{ order.order_number }}</Link
                            >
                            <span class="block text-xs text-muted-foreground"
                                >{{ order.items_count }} line{{
                                    order.items_count === 1 ? '' : 's'
                                }}
                                · {{ order.cashier }}</span
                            >
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(order.created_at) }}
                        </td>
                        <td class="px-4 py-3">{{ order.order_type }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="
                                    (statusTone[order.status] ?? ['neutral'])[0]
                                "
                                >{{
                                    (statusTone[order.status] ?? [
                                        null,
                                        order.status,
                                    ])[1]
                                }}</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ order.payment_method }}
                            <StatusBadge
                                v-if="
                                    order.payment_status ===
                                    'pending_verification'
                                "
                                tone="warning"
                                class="ml-1"
                                >To verify</StatusBadge
                            >
                        </td>
                        <td
                            class="px-4 py-3 text-right font-semibold whitespace-nowrap"
                        >
                            {{ formatMoney(order.grand_total) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="orders.length === 100" class="text-xs text-muted-foreground">
            Showing the latest 100. Search by order number to find older ones.
        </p>
    </div>
</template>
