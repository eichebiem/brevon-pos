<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import CashMovementForm from '@/components/shifts/CashMovementForm.vue';
import CashMovementList from '@/components/shifts/CashMovementList.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatMoney, selectClass } from '@/lib/format';
import { index as pos } from '@/routes/pos';
import { close, open } from '@/routes/staff/shift';
import type { CashMovementRow, MovementType } from '@/types/shifts';

type Shift = {
    id: number;
    number: string;
    status: 'open' | 'closed';
    location: string | null;
    location_code: string | null;
    terminal: string | null;
    opened_at: string;
    closed_at: string | null;
    opening_cash: string;
    expected_cash: string | null;
    counted_cash: string | null;
    variance: string | null;
};

type TerminalOption = {
    id: number;
    code: string;
    name: string;
    location: string | null;
    location_code: string | null;
};

const props = defineProps<{
    shift: Shift | null;
    terminals: TerminalOption[];
    recent: Shift[];
    movements: CashMovementRow[];
    movementTypes: MovementType[];
    canRecordCash: boolean;
}>();

const pendingCount = computed(
    () => props.movements.filter((m) => m.status === 'pending').length,
);

const { can } = usePermissions();

const openForm = useForm({
    terminal_id:
        props.terminals.length === 1 ? String(props.terminals[0].id) : '',
    opening_cash: '',
});

const closeForm = useForm({ counted_cash: '', closing_notes: '' });

// Pre-select the only free terminal, including after a shift is closed.
watch(
    () => props.terminals,
    (terminals) => {
        if (terminals.length === 1 && openForm.terminal_id === '') {
            openForm.terminal_id = String(terminals[0].id);
        }
    },
);

function openShift(): void {
    openForm.post(open().url, { preserveScroll: true });
}

function closeShift(): void {
    if (!props.shift) {
        return;
    }

    closeForm.post(close(props.shift.id).url, {
        preserveScroll: true,
        onSuccess: () => closeForm.reset(),
    });
}

/** Group terminals by location for the picker. */
const groups = computed(() => {
    const byLocation = new Map<string, TerminalOption[]>();

    for (const terminal of props.terminals) {
        const key = `${terminal.location_code} · ${terminal.location}`;
        byLocation.set(key, [...(byLocation.get(key) ?? []), terminal]);
    }

    return [...byLocation.entries()];
});

const varianceTone = (value: string | null) =>
    value === null || Number(value) === 0
        ? 'success'
        : Number(value) > 0
          ? 'info'
          : 'danger';
</script>

<template>
    <Head title="Shift" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <h1
            class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
        >
            My shift
        </h1>

        <!-- Open shift -->
        <section
            v-if="shift"
            class="grid gap-5 rounded-xl border border-border bg-card p-5"
            aria-labelledby="current-heading"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="current-heading" class="text-lg font-semibold">
                        Shift {{ shift.number }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ shift.location_code }} · {{ shift.location }} ·
                        terminal {{ shift.terminal }}
                    </p>
                </div>
                <StatusBadge tone="success">Open</StatusBadge>
            </div>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Opened</dt>
                    <dd class="font-medium">
                        {{ formatDateTime(shift.opened_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Opening cash</dt>
                    <dd class="font-medium">
                        {{ formatMoney(shift.opening_cash) }}
                    </dd>
                </div>
            </dl>
            <div v-if="can('pos.access')">
                <Button variant="outline" as-child>
                    <Link :href="pos()"
                        ><ShoppingCart aria-hidden="true" /> Go to POS</Link
                    >
                </Button>
            </div>

            <!-- Cash added or taken out during the shift (FR-076) -->
            <section
                class="grid grid-cols-[minmax(0,1fr)] gap-4 border-t border-border pt-5"
                aria-labelledby="cash-heading"
            >
                <h3 id="cash-heading" class="font-semibold">Cash in and out</h3>
                <CashMovementForm
                    v-if="canRecordCash"
                    :shift-id="shift.id"
                    :types="movementTypes"
                />
                <div class="rounded-lg border border-border">
                    <CashMovementList :movements="movements" />
                </div>
            </section>

            <form
                v-if="can('shifts.close')"
                class="grid gap-4 border-t border-border pt-5"
                novalidate
                aria-labelledby="close-heading"
                @submit.prevent="closeShift"
            >
                <h3 id="close-heading" class="font-semibold">Close shift</h3>
                <p class="text-sm text-muted-foreground">
                    Count the cash in the drawer. The expected amount and the
                    difference are shown after closing. If your count does not
                    match, you will be asked to count again or explain the
                    difference.
                </p>
                <p
                    v-if="pendingCount"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
                    role="status"
                >
                    {{ pendingCount }} cash
                    {{ pendingCount === 1 ? 'movement is' : 'movements are' }}
                    waiting for a manager. The shift can close once they are
                    approved or rejected.
                </p>
                <div class="grid max-w-56 gap-2">
                    <Label for="counted_cash">Counted cash (₱)</Label>
                    <Input
                        id="counted_cash"
                        v-model="closeForm.counted_cash"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="0.00"
                    />
                    <InputError :message="closeForm.errors.counted_cash" />
                </div>
                <div class="grid gap-2">
                    <Label for="closing_notes">Notes</Label>
                    <textarea
                        id="closing_notes"
                        v-model="closeForm.closing_notes"
                        rows="2"
                        class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    />
                    <InputError :message="closeForm.errors.closing_notes" />
                </div>
                <div>
                    <Button
                        type="submit"
                        :disabled="closeForm.processing"
                        data-test="close-shift"
                    >
                        <Spinner v-if="closeForm.processing" />
                        Close shift
                    </Button>
                </div>
            </form>
        </section>

        <!-- No open shift -->
        <form
            v-else-if="can('shifts.open')"
            class="grid gap-5 rounded-xl border border-border bg-card p-5"
            novalidate
            aria-labelledby="open-heading"
            @submit.prevent="openShift"
        >
            <h2 id="open-heading" class="text-lg font-semibold">
                Open a shift
            </h2>
            <p
                v-if="terminals.length === 0"
                class="rounded-lg border border-border bg-secondary p-3 text-sm"
                role="note"
            >
                No terminal is free at a location you are assigned to that is
                open today. Ask a manager to assign you or to add a terminal.
            </p>
            <template v-else>
                <div class="grid gap-2">
                    <Label for="terminal_id">Terminal</Label>
                    <select
                        id="terminal_id"
                        v-model="openForm.terminal_id"
                        :class="[selectClass, 'h-11']"
                    >
                        <option value="" disabled>Choose a terminal…</option>
                        <optgroup
                            v-for="[label, items] in groups"
                            :key="label"
                            :label="label"
                        >
                            <option
                                v-for="t in items"
                                :key="t.id"
                                :value="String(t.id)"
                            >
                                {{ t.code }} · {{ t.name }}
                            </option>
                        </optgroup>
                    </select>
                    <InputError :message="openForm.errors.terminal_id" />
                </div>
                <div class="grid max-w-56 gap-2">
                    <Label for="opening_cash">Opening cash (₱)</Label>
                    <Input
                        id="opening_cash"
                        v-model="openForm.opening_cash"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="0.00"
                    />
                    <InputError :message="openForm.errors.opening_cash" />
                </div>
                <div>
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="openForm.processing"
                        data-test="open-shift"
                    >
                        <Spinner v-if="openForm.processing" />
                        Open shift
                    </Button>
                </div>
            </template>
        </form>

        <p v-else class="text-sm text-muted-foreground">
            You have no open shift.
        </p>

        <!-- Recent closed shifts -->
        <section
            v-if="recent.length"
            class="overflow-x-auto rounded-xl border border-border bg-card"
            aria-labelledby="recent-heading"
        >
            <h2 id="recent-heading" class="px-4 pt-4 font-semibold">
                Recent shifts
            </h2>
            <table class="mt-3 w-full text-left text-sm">
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Shift
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Closed
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Expected
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Counted
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Difference
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in recent"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.number }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.location_code }} · {{ row.terminal }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDateTime(row.closed_at) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ formatMoney(row.expected_cash) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ formatMoney(row.counted_cash) }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="varianceTone(row.variance)">{{
                                formatMoney(row.variance)
                            }}</StatusBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
