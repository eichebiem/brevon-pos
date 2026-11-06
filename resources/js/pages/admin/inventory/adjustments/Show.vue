<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
import { approve, index, reject } from '@/routes/admin/inventory/adjustments';

type Line = {
    id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    system_quantity: string;
    counted_quantity: string;
    variance_quantity: string;
    unit_cost: string | null;
};

const props = defineProps<{
    adjustment: {
        id: number;
        number: string;
        kind: 'count' | 'change';
        kind_label: string;
        status: 'pending' | 'posted' | 'rejected';
        status_label: string;
        reason: string;
        location: { code: string; name: string } | null;
        created_by: string | null;
        created_at: string;
        decided_by: string | null;
        decided_at: string | null;
        decision_notes: string | null;
        lines: Line[];
    };
    canDecide: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Counts & adjustments', href: index() }] },
});

const form = useForm({ notes: '' });

function decide(approving: boolean): void {
    form.post(
        (approving ? approve(props.adjustment.id) : reject(props.adjustment.id))
            .url,
        { preserveScroll: true },
    );
}

const tone = {
    pending: 'warning',
    posted: 'success',
    rejected: 'neutral',
} as const;

const signed = (value: string) =>
    value.startsWith('-')
        ? `−${formatQuantity(value.slice(1))}`
        : value === '0.000'
          ? '0'
          : `+${formatQuantity(value)}`;
</script>

<template>
    <Head :title="`Adjustment ${adjustment.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ adjustment.kind_label }} {{ adjustment.number }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ adjustment.location?.code }} ·
                    {{ adjustment.location?.name }} · entered by
                    {{ adjustment.created_by }}
                    {{ formatDateTime(adjustment.created_at) }}
                </p>
                <p class="mt-1 text-sm">
                    <span class="text-muted-foreground">Reason:</span>
                    {{ adjustment.reason }}
                </p>
            </div>
            <StatusBadge
                :tone="tone[adjustment.status]"
                data-test="adjustment-status"
                >{{ adjustment.status_label }}</StatusBadge
            >
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Adjustment lines
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            System showed
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            {{
                                adjustment.kind === 'count'
                                    ? 'Counted'
                                    : 'Becomes'
                            }}
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Change
                        </th>
                        <th
                            v-if="adjustment.status === 'posted'"
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Unit cost
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in adjustment.lines"
                        :key="line.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ line.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ line.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(line.system_quantity) }}
                            {{ line.unit }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(line.counted_quantity) }}
                            {{ line.unit }}
                        </td>
                        <td
                            class="px-4 py-3 text-right font-semibold tabular-nums"
                            :class="
                                line.variance_quantity.startsWith('-')
                                    ? 'text-destructive'
                                    : 'text-emerald-700 dark:text-emerald-400'
                            "
                        >
                            {{ signed(line.variance_quantity) }}
                        </td>
                        <td
                            v-if="adjustment.status === 'posted'"
                            class="px-4 py-3 text-right tabular-nums"
                        >
                            {{ formatMoney(line.unit_cost) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="adjustment.decided_by"
            class="text-sm"
            data-test="adjustment-decision"
        >
            {{ adjustment.status === 'posted' ? 'Posted' : 'Rejected' }} by
            {{ adjustment.decided_by }}
            {{ formatDateTime(adjustment.decided_at)
            }}<template v-if="adjustment.decision_notes">
                — {{ adjustment.decision_notes }}</template
            >
        </p>

        <form
            v-if="canDecide"
            class="grid max-w-xl gap-4 rounded-xl border border-border bg-card p-5"
            novalidate
            @submit.prevent
        >
            <h2 class="font-semibold">Approve or reject</h2>
            <p class="text-sm text-muted-foreground">
                Approving posts each change to stock now. Lines that would take
                stock below zero are refused; reject and count again.
            </p>
            <div class="grid gap-2">
                <Label for="notes">Notes (needed to reject)</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="500"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <InputError
                    :message="
                        form.errors.notes ??
                        (form.errors as Record<string, string>).adjustment
                    "
                />
            </div>
            <div class="flex gap-2">
                <Button
                    type="button"
                    :disabled="form.processing"
                    data-test="adjustment-approve"
                    @click="decide(true)"
                >
                    <Spinner v-if="form.processing" /> Approve and post
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing || form.notes.trim().length < 3"
                    data-test="adjustment-reject"
                    @click="decide(false)"
                    >Reject</Button
                >
            </div>
        </form>
    </div>
</template>
