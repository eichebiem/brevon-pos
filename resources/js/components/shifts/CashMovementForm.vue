<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { store } from '@/routes/cash-movements';
import type { MovementType } from '@/types/shifts';

const props = defineProps<{ shiftId: number; types: MovementType[] }>();

// FR-076: every movement needs an amount and a reason; the server decides
// whether it needs a manager's approval (D-127).
const form = useForm({
    movement_type: props.types[0]?.value ?? '',
    amount: '',
    reason: '',
});

function submit(): void {
    form.post(store(props.shiftId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('amount', 'reason'),
    });
}

const hints: Record<string, string> = {
    cash_in: 'Cash added to the drawer, e.g. more coins for change.',
    cash_out: 'Cash taken out, e.g. a safe drop to the office.',
    cash_paid_out: 'Cash spent from the drawer, e.g. ice or delivery.',
};
</script>

<template>
    <form
        class="grid gap-4"
        novalidate
        aria-labelledby="movement-heading"
        @submit.prevent="submit"
    >
        <h3 id="movement-heading" class="font-semibold">Record cash</h3>
        <div class="grid gap-4 sm:grid-cols-[auto_10rem_minmax(0,1fr)]">
            <div class="grid gap-2">
                <Label for="movement_type">Type</Label>
                <select
                    id="movement_type"
                    v-model="form.movement_type"
                    :class="[selectClass, 'h-11']"
                    aria-describedby="movement-type-hint"
                >
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <InputError :message="form.errors.movement_type" />
            </div>
            <div class="grid gap-2">
                <Label for="movement_amount">Amount (₱)</Label>
                <Input
                    id="movement_amount"
                    v-model="form.amount"
                    class="h-11"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="0.00"
                />
                <InputError :message="form.errors.amount" />
            </div>
            <div class="grid gap-2">
                <Label for="movement_reason">Reason</Label>
                <Input
                    id="movement_reason"
                    v-model="form.reason"
                    class="h-11"
                    maxlength="500"
                    autocomplete="off"
                    placeholder="What is the cash for?"
                />
                <InputError :message="form.errors.reason" />
            </div>
        </div>
        <p id="movement-type-hint" class="text-xs text-muted-foreground">
            {{ hints[form.movement_type] }}
        </p>
        <div>
            <Button
                type="submit"
                variant="outline"
                class="min-h-11"
                :disabled="form.processing"
                data-test="record-cash"
            >
                <Spinner v-if="form.processing" /> Record
            </Button>
        </div>
    </form>
</template>
