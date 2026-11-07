<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/format';
import type { PosPaymentMethod } from '@/types/pos';

const open = defineModel<boolean>('open', { required: true });
const tendered = defineModel<string>('tendered', { required: true });
const props = defineProps<{
    method: PosPaymentMethod | null;
    total: string | null;
    change: string | null;
    processing: boolean;
    errors: Record<string, string | undefined>;
}>();
const emit = defineEmits<{
    confirm: [payment: { reference: string }];
}>();

const reference = ref('');
// D-168: the cashier confirms the transfer arrived before finishing the sale.
const received = ref(false);
const isCash = computed(() => props.method?.type === 'cash');

watch(open, (isOpen) => {
    if (isOpen) {
        tendered.value = '';
        reference.value = '';
        received.value = false;
    }
});

// Common notes, shown only when they cover the total (display comparison only).
const quickAmounts = computed(() =>
    ['100', '200', '500', '1000'].filter(
        (a) => props.total !== null && Number(a) >= Number(props.total),
    ),
);

const ready = computed(() => {
    if (props.processing || props.total === null) {
        return false;
    }
    if (isCash.value) {
        return tendered.value !== '' && props.change !== null;
    }

    return (
        received.value &&
        (!props.method?.requires_reference ||
            reference.value.trim().length >= 6)
    );
});

function confirm(): void {
    if (ready.value) {
        emit('confirm', {
            reference: reference.value.trim(),
        });
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-md"
            @escape-key-down="processing && $event.preventDefault()"
        >
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >{{ method?.name }} payment</DialogTitle
                >
                <DialogDescription>
                    Total to pay
                    <strong
                        class="block font-display text-4xl text-brevon-navy dark:text-brevon-gold-light"
                        data-test="pos-pay-total"
                        >{{ formatMoney(total) }}</strong
                    >
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="confirm">
                <template v-if="isCash">
                    <div class="grid gap-2">
                        <Label for="tendered">Cash received</Label>
                        <Input
                            id="tendered"
                            v-model="tendered"
                            v-focus
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="0.00"
                            class="h-14 text-2xl"
                            data-test="pos-tendered"
                        />
                        <InputError
                            :message="errors['payment.amount_tendered']"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            @click="tendered = total ?? ''"
                            >Exact</Button
                        >
                        <Button
                            v-for="amount in quickAmounts"
                            :key="amount"
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            @click="tendered = amount"
                            >{{ formatMoney(amount) }}</Button
                        >
                    </div>
                    <p
                        class="flex items-baseline justify-between rounded-lg bg-secondary px-4 py-3"
                        aria-live="polite"
                    >
                        <span class="text-sm font-medium">Change</span>
                        <strong
                            class="font-display text-3xl"
                            data-test="pos-change"
                            >{{
                                change === null ? '—' : formatMoney(change)
                            }}</strong
                        >
                    </p>
                </template>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="reference"
                            >{{ method?.name }} reference number</Label
                        >
                        <Input
                            id="reference"
                            v-model="reference"
                            v-focus
                            autocomplete="off"
                            maxlength="40"
                            class="h-12 text-lg tracking-wider uppercase"
                            aria-describedby="reference-hint"
                            data-test="pos-reference"
                        />
                        <p
                            id="reference-hint"
                            class="text-xs text-muted-foreground"
                        >
                            Copy it from the customer's
                            {{ method?.name }} receipt (6–40 letters or digits).
                            Each number can be used once.
                        </p>
                        <InputError :message="errors['payment.reference']" />
                    </div>
                    <label
                        class="flex min-h-11 items-center gap-2 rounded-lg border border-border px-3 text-sm"
                    >
                        <input
                            v-model="received"
                            type="checkbox"
                            class="size-4 accent-brevon-navy"
                            data-test="pos-received"
                        />
                        I checked that this payment arrived in the shop's
                        {{ method?.name }} account
                    </label>
                </template>

                <InputError :message="errors['payment.method']" />

                <DialogFooter>
                    <Button
                        type="submit"
                        size="lg"
                        class="min-h-14 w-full text-lg"
                        :disabled="!ready"
                        data-test="pos-pay-confirm"
                    >
                        <Spinner v-if="processing" />
                        Complete sale
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
