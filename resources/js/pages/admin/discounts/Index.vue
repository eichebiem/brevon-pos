<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/discounts';

type Row = {
    id: number;
    code: string | null;
    name: string;
    senior_pwd: boolean;
    type: 'percentage' | 'fixed';
    value: string;
    scope_label: string | null;
    starts_at: string | null;
    ends_at: string | null;
    uses: number;
    max_uses: number | null;
    requires_approval: boolean;
    is_active: boolean;
    status: 'on' | 'off' | 'scheduled' | 'ended' | 'used_up';
};

defineProps<{ discounts: Row[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Discounts', href: index() }] },
});

const statusLabel: Record<Row['status'], string> = {
    on: 'On',
    off: 'Off',
    scheduled: 'Starts later',
    ended: 'Ended',
    used_up: 'Used up',
};
const statusTone: Record<Row['status'], 'success' | 'neutral' | 'warning'> = {
    on: 'success',
    off: 'neutral',
    scheduled: 'warning',
    ended: 'neutral',
    used_up: 'neutral',
};

function amount(row: Row): string {
    return row.type === 'percentage'
        ? `${Number(row.value)}%`
        : formatMoney(row.value);
}
</script>

<template>
    <Head title="Discounts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Discounts
                </h1>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    Discounts the counter can give. One discount per order.
                    Changing a discount never changes past sales.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New discount</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Discounts
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Discount
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Amount
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Applies to
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Valid
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Used
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
                    <tr v-if="discounts.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No discounts yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in discounts"
                        :key="row.id"
                        class="border-t border-border align-top"
                        :data-test="`discount-${row.code ?? row.id}`"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.code ?? 'No code' }}
                                <template v-if="row.senior_pwd">
                                    · records the beneficiary's name, ID and
                                    address</template
                                >
                                <template v-if="row.requires_approval">
                                    · manager approves</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ amount(row) }}
                        </td>
                        <td class="px-4 py-3">{{ row.scope_label }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            <template v-if="!row.starts_at && !row.ends_at"
                                >Always</template
                            >
                            <template v-else>
                                {{
                                    row.starts_at
                                        ? formatDateTime(row.starts_at)
                                        : 'Now'
                                }}
                                –
                                {{
                                    row.ends_at
                                        ? formatDateTime(row.ends_at)
                                        : 'no end'
                                }}
                            </template>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ row.uses
                            }}<template v-if="row.max_uses !== null">
                                / {{ row.max_uses }}</template
                            >
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="statusTone[row.status]">{{
                                statusLabel[row.status]
                            }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link
                                    :href="edit(row.id)"
                                    :aria-label="`Edit ${row.name}`"
                                    ><Pencil aria-hidden="true" /> Edit</Link
                                >
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
