<script setup lang="ts">
import { formatDateTime, formatMoney } from '@/lib/format';
import type { CashMovementRow } from '@/types/shifts';

defineProps<{
    movements: CashMovementRow[];
}>();

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
                </tr>
            </thead>
            <tbody>
                <tr v-if="movements.length === 0">
                    <td
                        colspan="3"
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
                        <span
                            v-if="row.decided_by"
                            class="block text-xs text-muted-foreground"
                            >Approved by {{ row.decided_by }}</span
                        >
                    </td>
                    <td
                        class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ shown(row) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
