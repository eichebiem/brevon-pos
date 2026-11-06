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
import { formatMoney } from '@/lib/format';
import type { Beneficiary, ChosenDiscount, PosDiscount } from '@/types/pos';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    discounts: PosDiscount[];
    lines: { key: string; label: string }[];
    current: ChosenDiscount | null;
    errors: Record<string, string | undefined>;
}>();
const emit = defineEmits<{
    apply: [choice: ChosenDiscount];
    remove: [];
}>();

const selectedId = ref<number | null>(null);
const beneficiary = ref<Beneficiary>({
    type: '',
    name: '',
    card_number: '',
    address: '',
});
const lineKeys = ref<string[]>([]);
const reason = ref('');

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }
    selectedId.value = props.current?.id ?? null;
    beneficiary.value = props.current?.beneficiary
        ? { ...props.current.beneficiary }
        : { type: '', name: '', card_number: '', address: '' };
    lineKeys.value = props.current?.lineKeys ?? props.lines.map((l) => l.key);
    reason.value = props.current?.reason ?? '';
});

const selected = computed(() =>
    props.discounts.find((d) => d.id === selectedId.value),
);

function amount(d: PosDiscount): string {
    return d.type === 'percentage'
        ? `${Number(d.value)}% off`
        : `${formatMoney(d.value)} off`;
}

const ready = computed(() => {
    if (!selected.value) {
        return false;
    }
    if (!selected.value.senior_pwd) {
        return true;
    }
    const b = beneficiary.value;

    return (
        b.type !== '' &&
        b.name.trim() !== '' &&
        b.card_number.trim() !== '' &&
        b.address.trim() !== '' &&
        lineKeys.value.length > 0
    );
});

function apply(): void {
    if (!ready.value || !selected.value) {
        return;
    }
    const all = lineKeys.value.length === props.lines.length;
    emit('apply', {
        id: selected.value.id,
        lineKeys:
            selected.value.senior_pwd && !all ? [...lineKeys.value] : null,
        beneficiary: selected.value.senior_pwd
            ? { ...beneficiary.value }
            : null,
        reason: reason.value.trim(),
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Discount</DialogTitle
                >
                <DialogDescription>
                    One discount per order. The total is worked out again after
                    you apply it.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" novalidate @submit.prevent="apply">
                <fieldset class="grid gap-2">
                    <legend class="sr-only">Choose a discount</legend>
                    <p
                        v-if="discounts.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No discounts are on right now.
                    </p>
                    <label
                        v-for="d in discounts"
                        :key="d.id"
                        :class="[
                            'flex min-h-14 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2',
                            selectedId === d.id
                                ? 'border-brevon-navy bg-brevon-cream/60 dark:bg-brevon-navy/30'
                                : 'border-border',
                        ]"
                        :data-test="`pos-discount-${d.code ?? d.id}`"
                    >
                        <input
                            v-model="selectedId"
                            type="radio"
                            name="discount"
                            :value="d.id"
                            class="size-4 accent-brevon-navy"
                        />
                        <span class="flex-1">
                            <span class="block text-sm font-semibold">{{
                                d.name
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ amount(d) }}
                                <template v-if="d.applies_to">
                                    · {{ d.applies_to }} only</template
                                >
                                <template v-if="d.requires_approval">
                                    · manager approves</template
                                >
                            </span>
                        </span>
                    </label>
                    <InputError :message="errors.discount" />
                </fieldset>

                <template v-if="selected?.senior_pwd">
                    <fieldset
                        class="grid gap-3 rounded-lg border border-border p-3"
                    >
                        <legend class="px-1 text-sm font-semibold">
                            Beneficiary (as on the ID)
                        </legend>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="beneficiary.type"
                                    type="radio"
                                    value="senior_citizen"
                                    class="size-4 accent-brevon-navy"
                                    data-test="pos-beneficiary-senior"
                                />
                                Senior citizen
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="beneficiary.type"
                                    type="radio"
                                    value="pwd"
                                    class="size-4 accent-brevon-navy"
                                />
                                PWD
                            </label>
                        </div>
                        <InputError
                            :message="errors['discount.beneficiary.type']"
                        />
                        <div class="grid gap-1">
                            <Label for="beneficiary-name">Full name</Label>
                            <Input
                                id="beneficiary-name"
                                v-model="beneficiary.name"
                                maxlength="150"
                                autocomplete="off"
                                data-test="pos-beneficiary-name"
                            />
                            <InputError
                                :message="errors['discount.beneficiary.name']"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="beneficiary-card">ID card number</Label>
                            <Input
                                id="beneficiary-card"
                                v-model="beneficiary.card_number"
                                maxlength="40"
                                autocomplete="off"
                                data-test="pos-beneficiary-card"
                            />
                            <InputError
                                :message="
                                    errors['discount.beneficiary.card_number']
                                "
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="beneficiary-address">Address</Label>
                            <Input
                                id="beneficiary-address"
                                v-model="beneficiary.address"
                                maxlength="255"
                                autocomplete="off"
                                data-test="pos-beneficiary-address"
                            />
                            <InputError
                                :message="
                                    errors['discount.beneficiary.address']
                                "
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Kept with this order only and shown masked on the
                            receipt. Never saved for another visit.
                        </p>
                    </fieldset>

                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-semibold">
                            Items for the beneficiary
                        </legend>
                        <label
                            v-for="line in lines"
                            :key="line.key"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="lineKeys"
                                type="checkbox"
                                :value="line.key"
                                class="size-4 accent-brevon-navy"
                            />
                            {{ line.label }}
                        </label>
                        <InputError :message="errors['discount.lines']" />
                    </fieldset>
                </template>

                <div v-else-if="selected" class="grid gap-1">
                    <Label for="discount-reason">Reason (optional)</Label>
                    <Input
                        id="discount-reason"
                        v-model="reason"
                        maxlength="255"
                        placeholder="No names or phone numbers"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        v-if="current"
                        type="button"
                        variant="outline"
                        class="min-h-11 text-red-700"
                        @click="
                            emit('remove');
                            open = false;
                        "
                        >Remove discount</Button
                    >
                    <Button
                        type="submit"
                        class="min-h-11"
                        :disabled="!ready"
                        data-test="pos-discount-apply"
                        >Apply</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
