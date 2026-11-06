<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney } from '@/lib/format';
import { approve, reject } from '@/routes/cash-movements';
import type { CashMovementRow } from '@/types/shifts';

const props = defineProps<{
    movements: CashMovementRow[];
    /** The viewer may approve or reject pending movements (not their own). */
    canDecide?: boolean;
    me?: number;
}>();

const statusTone = {
    pending: 'warning',
    approved: 'success',
    rejected: 'neutral',
} as const;

const statusLabel = {
    pending: 'Waiting for approval',
    approved: 'Approved',
    rejected: 'Rejected',
};

const deciding = ref<{ row: CashMovementRow; approve: boolean } | null>(null);
const open = computed({
    get: () => deciding.value !== null,
    set: (v) => {
        if (!v) {
            deciding.value = null;
        }
    },
});
const form = useForm({ notes: '' });

function start(row: CashMovementRow, approving: boolean): void {
    form.reset();
    form.clearErrors();
    deciding.value = { row, approve: approving };
}

function submit(): void {
    if (!deciding.value) {
        return;
    }
    const { row, approve: approving } = deciding.value;
    form.post((approving ? approve(row.id) : reject(row.id)).url, {
        preserveScroll: true,
        onSuccess: () => {
            deciding.value = null;
        },
    });
}

/** Cash in adds to the drawer, the rest take out; corrections carry their own sign. */
function shown(row: CashMovementRow): string {
    if (row.type === 'correction') {
        return row.amount.startsWith('-')
            ? formatMoney(row.amount)
            : `+${formatMoney(row.amount)}`;
    }

    return `${row.type === 'cash_in' ? '+' : '−'}${formatMoney(row.amount)}`;
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">
                Cash movements
            </caption>
            <thead class="bg-secondary text-secondary-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-semibold">When</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">
                        Amount
                    </th>
                    <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="movements.length === 0">
                    <td
                        colspan="4"
                        class="px-4 py-8 text-center text-muted-foreground"
                    >
                        No cash has been added or taken out.
                    </td>
                </tr>
                <tr
                    v-for="row in movements"
                    :key="row.id"
                    class="border-t border-border align-top"
                    :data-test="`movement-${row.id}`"
                >
                    <td class="px-4 py-3 whitespace-nowrap">
                        {{ formatDateTime(row.at) }}
                        <span class="block text-xs text-muted-foreground">{{
                            row.by
                        }}</span>
                    </td>
                    <td class="min-w-48 px-4 py-3">
                        <span class="font-medium">{{ row.type_label }}</span
                        ><template v-if="row.corrects">
                            to {{ row.corrects }} cash</template
                        >
                        <span class="block text-xs text-muted-foreground">{{
                            row.reason
                        }}</span>
                    </td>
                    <td
                        class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums"
                        :class="{
                            'line-through opacity-60':
                                row.status === 'rejected',
                        }"
                    >
                        {{ shown(row) }}
                    </td>
                    <td class="px-4 py-3">
                        <StatusBadge :tone="statusTone[row.status]">{{
                            statusLabel[row.status]
                        }}</StatusBadge>
                        <span
                            v-if="row.decided_by && row.status !== 'pending'"
                            class="block pt-1 text-xs text-muted-foreground"
                            >{{ row.decided_by
                            }}<template v-if="row.decision_notes">
                                · {{ row.decision_notes }}</template
                            ></span
                        >
                        <div
                            v-if="
                                canDecide &&
                                row.status === 'pending' &&
                                row.by_id !== me
                            "
                            class="flex gap-2 pt-2"
                        >
                            <Button
                                size="sm"
                                data-test="movement-approve"
                                @click="start(row, true)"
                                >Approve</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                data-test="movement-reject"
                                @click="start(row, false)"
                                >Reject</Button
                            >
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Dialog v-model:open="open">
        <DialogContent v-if="deciding" class="sm:max-w-md">
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle
                        >{{ deciding.approve ? 'Approve' : 'Reject' }}
                        {{ deciding.row.type_label.toLowerCase() }} of
                        {{ formatMoney(deciding.row.amount) }}?</DialogTitle
                    >
                    <DialogDescription>
                        {{ deciding.row.reason }} — recorded by
                        {{ deciding.row.by }}.
                        <template v-if="!deciding.approve"
                            >Rejected cash stays in the drawer total.</template
                        >
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="decision-notes"
                        >Notes{{ deciding.approve ? ' (optional)' : '' }}</Label
                    >
                    <textarea
                        id="decision-notes"
                        v-model="form.notes"
                        v-focus
                        rows="2"
                        maxlength="500"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    />
                    <InputError
                        :message="
                            form.errors.notes ??
                            (form.errors as Record<string, string>).movement
                        "
                    />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            >Back</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        :variant="deciding.approve ? 'default' : 'destructive'"
                        :disabled="
                            form.processing ||
                            (!deciding.approve && form.notes.trim() === '')
                        "
                        data-test="movement-decide-confirm"
                    >
                        <Spinner v-if="form.processing" />
                        {{ deciding.approve ? 'Approve' : 'Reject' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
