<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Minus, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/format';
import { store } from '@/routes/orders/refunds';
import type { RefundForm } from '@/types/pos';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    order: { id: number; order_number: string };
    refund: RefundForm;
}>();

type LineState = { quantity: number; return_to_stock: boolean };
const lines = ref<Record<number, LineState>>({});

const form = useForm({
    reason: '',
    reference: '',
    approver_username: '',
    approver_password: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        lines.value = Object.fromEntries(
            props.refund.lines.map((l) => [
                l.id,
                { quantity: 0, return_to_stock: false },
            ]),
        );
        form.reset();
        form.clearErrors();
    }
});

const milli = (q: string | number): number => Math.round(Number(q) * 1000);

/** Same rule as the server (RefundableLine::amountFor): share of what is left, half up. */
function amountFor(
    line: RefundForm['lines'][number],
    quantity: number,
): number {
    const left = milli(line.remaining_quantity);
    if (left <= 0 || quantity <= 0) {
        return 0;
    }
    const minor = Math.round(Number(line.remaining_amount) * 100);

    return Math.floor((2 * minor * milli(quantity) + left) / (2 * left));
}

const totalMinor = computed(() =>
    props.refund.lines.reduce(
        (sum, l) => sum + amountFor(l, lines.value[l.id]?.quantity ?? 0),
        0,
    ),
);
const total = computed(() => (totalMinor.value / 100).toFixed(2));
const needsApproval = computed(
    () =>
        !props.refund.can_approve &&
        totalMinor.value > Math.round(Number(props.refund.approval_over) * 100),
);
const blockedNoDrawer = computed(
    () => props.refund.is_cash && !props.refund.has_drawer,
);

function step(id: number, by: number): void {
    const line = props.refund.lines.find((l) => l.id === id);
    const state = lines.value[id];
    if (!line || !state) {
        return;
    }
    const max = Number(line.remaining_quantity);
    state.quantity = Math.min(max, Math.max(0, state.quantity + by));
    if (state.quantity === 0) {
        state.return_to_stock = false;
    }
}

function everything(): void {
    for (const l of props.refund.lines) {
        const state = lines.value[l.id];
        if (state) {
            state.quantity = Number(l.remaining_quantity);
        }
    }
}

const canSubmit = computed(
    () =>
        !form.processing &&
        !blockedNoDrawer.value &&
        totalMinor.value > 0 &&
        form.reason.trim().length >= 3 &&
        (!props.refund.requires_reference ||
            form.reference.trim().length >= 6) &&
        (!needsApproval.value ||
            (form.approver_username.trim() !== '' &&
                form.approver_password !== '')),
);

const lineErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'lines' || key.startsWith('lines.'))
        .map(([, message]) => message),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        seen_refunded: props.refund.refunded,
        reference: props.refund.requires_reference ? data.reference : null,
        approver_username: needsApproval.value ? data.approver_username : null,
        approver_password: needsApproval.value ? data.approver_password : null,
        lines: props.refund.lines
            .filter((l) => (lines.value[l.id]?.quantity ?? 0) > 0)
            .map((l) => ({
                order_item_id: l.id,
                quantity: String(lines.value[l.id]?.quantity ?? 0),
                return_to_stock: lines.value[l.id]?.return_to_stock ?? false,
            })),
    })).post(store(props.order.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: () => {
            form.approver_password = '';
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle
                        >Refund order {{ order.order_number }}</DialogTitle
                    >
                    <DialogDescription>
                        Choose what the customer is giving back.
                        <template v-if="refund.is_cash"
                            >The cash comes out of your drawer.</template
                        >
                        <template v-else
                            >Send the money back from the shop's
                            {{ refund.method }} account, then enter the
                            reference.</template
                        >
                        The original sale stays on record.
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="blockedNoDrawer"
                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
                    role="alert"
                    data-test="refund-no-drawer"
                >
                    This was paid in cash. Open your shift at this location to
                    hand the cash back from your drawer.
                </p>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Items</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="refund-everything"
                            @click="everything"
                            >Everything left</Button
                        >
                    </div>
                    <ul class="grid gap-2" data-test="refund-lines">
                        <li
                            v-for="line in refund.lines"
                            :key="line.id"
                            class="grid gap-2 rounded-md border border-border p-3 text-sm"
                            :class="
                                Number(line.remaining_quantity) <= 0
                                    ? 'opacity-60'
                                    : ''
                            "
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span>
                                    <strong>{{ line.name }}</strong>
                                    <template v-if="line.variant">
                                        ({{ line.variant }})</template
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            Number(line.remaining_quantity)
                                        }}
                                        of {{ Number(line.sold) }} left ·
                                        {{
                                            formatMoney(line.remaining_amount)
                                        }}</span
                                    >
                                </span>
                                <span
                                    v-if="Number(line.remaining_quantity) > 0"
                                    class="flex items-center gap-1"
                                >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-11"
                                        :aria-label="`One less ${line.name}`"
                                        @click="step(line.id, -1)"
                                        ><Minus aria-hidden="true"
                                    /></Button>
                                    <span
                                        class="w-8 text-center font-semibold tabular-nums"
                                        :data-test="`refund-qty-${line.id}`"
                                        >{{
                                            lines[line.id]?.quantity ?? 0
                                        }}</span
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-11"
                                        :aria-label="`One more ${line.name}`"
                                        :data-test="`refund-plus-${line.id}`"
                                        @click="step(line.id, 1)"
                                        ><Plus aria-hidden="true"
                                    /></Button>
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >Refunded</span
                                >
                            </div>
                            <label
                                v-if="
                                    line.can_return_to_stock &&
                                    (lines[line.id]?.quantity ?? 0) > 0 &&
                                    lines[line.id]
                                "
                                class="flex items-start gap-2"
                            >
                                <input
                                    v-model="lines[line.id]!.return_to_stock"
                                    type="checkbox"
                                    class="mt-0.5 size-4 accent-brevon-navy"
                                />
                                <span
                                    >Put back in stock
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Only if it is unopened and can be sold
                                        again.</span
                                    ></span
                                >
                            </label>
                        </li>
                    </ul>
                    <p
                        v-for="(message, i) in lineErrors"
                        :key="i"
                        class="text-sm text-red-600"
                    >
                        {{ message }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="refund-reason">Reason</Label>
                    <textarea
                        id="refund-reason"
                        v-model="form.reason"
                        rows="2"
                        maxlength="500"
                        required
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        placeholder="e.g. Wrong flavour given"
                        aria-describedby="refund-reason-hint"
                    />
                    <p
                        id="refund-reason-hint"
                        class="text-xs text-muted-foreground"
                    >
                        No customer names or phone numbers.
                    </p>
                    <InputError :message="form.errors.reason" />
                </div>

                <div v-if="refund.requires_reference" class="grid gap-2">
                    <Label for="refund-reference"
                        >{{ refund.method }} reference of the money sent
                        back</Label
                    >
                    <Input
                        id="refund-reference"
                        v-model="form.reference"
                        autocomplete="off"
                        maxlength="40"
                        class="font-mono uppercase"
                    />
                    <InputError :message="form.errors.reference" />
                </div>

                <fieldset
                    v-if="needsApproval"
                    class="grid gap-3 rounded-md border border-border p-3"
                    data-test="refund-approval"
                >
                    <legend class="px-1 text-sm font-semibold">
                        Manager approval
                    </legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label for="refund-approver">Username</Label>
                            <Input
                                id="refund-approver"
                                v-model="form.approver_username"
                                autocomplete="off"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="refund-approver-password"
                                >Password</Label
                            >
                            <Input
                                id="refund-approver-password"
                                v-model="form.approver_password"
                                type="password"
                                autocomplete="off"
                            />
                        </div>
                    </div>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).approver
                        "
                    />
                </fieldset>

                <InputError
                    :message="(form.errors as Record<string, string>).refund"
                />

                <div
                    class="flex items-center justify-between border-t border-border pt-3 text-lg font-semibold"
                >
                    <span>To give back</span>
                    <span data-test="refund-total">{{
                        formatMoney(total)
                    }}</span>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            >Close</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="!canSubmit"
                        data-test="refund-confirm"
                    >
                        <Spinner v-if="form.processing" /> Refund
                        {{ formatMoney(total) }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
