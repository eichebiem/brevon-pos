<script setup lang="ts">
import { toast } from 'vue-sonner';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { Label } from '@/components/ui/label';
import { formatDateTime, formatMoney } from '@/lib/format';
import { reject, verify } from '@/routes/payments';
import { show } from '@/routes/pos/orders';

type PendingPayment = {
    id: number;
    order_id: number;
    order_number: string;
    order_status: string;
    created_at: string;
    cashier: string | null;
    method: string;
    amount: string;
    reference_number: string;
};

defineProps<{
    site: { code: string; name: string } | null;
    payments: PendingPayment[];
}>();

const busy = ref<number | null>(null);

// BR-019: confirm only after seeing the transfer in the shop's GCash app.
function confirmReceived(payment: PendingPayment): void {
    busy.value = payment.id;
    router.post(
        verify(payment.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not save.');
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

const rejectOpen = ref(false);
const rejecting = ref<PendingPayment | null>(null);
const rejectForm = useForm({ reason: '' });

function askReject(payment: PendingPayment): void {
    rejecting.value = payment;
    rejectForm.reset();
    rejectForm.clearErrors();
    rejectOpen.value = true;
}

function submitReject(): void {
    if (!rejecting.value) {
        return;
    }
    rejectForm.post(reject(rejecting.value.id).url, {
        preserveScroll: true,
        onError: (errors) => {
            // "Already checked" comes back on `payment`, not on the reason field.
            const other = (errors as Record<string, string>).payment;
            if (other) {
                toast.error(other);
            }
        },
        onSuccess: () => {
            rejectOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="GCash to check" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                GCash to check
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ site ? `${site.code} · ${site.name}` : 'No location' }} ·
                Open the shop's GCash app and find each reference number before
                confirming.
            </p>
        </header>

        <div
            v-if="payments.length === 0"
            class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground"
        >
            Nothing to check. Every GCash payment here is confirmed.
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-border bg-card"
        >
            <table class="w-full text-sm">
                <thead class="bg-secondary text-left">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Order
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Time
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Reference
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Amount
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in payments"
                        :key="payment.id"
                        class="border-t border-border"
                        :data-test="`pending-${payment.reference_number}`"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(payment.order_id)"
                                class="font-mono font-semibold underline-offset-2 hover:underline"
                                >{{ payment.order_number }}</Link
                            >
                            <span class="block text-xs text-muted-foreground"
                                >{{ payment.method }} ·
                                {{ payment.cashier }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDateTime(payment.created_at) }}
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-base tracking-wider"
                        >
                            {{ payment.reference_number }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">
                            {{ formatMoney(payment.amount) }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Button
                                    size="sm"
                                    :disabled="busy === payment.id"
                                    :data-test="`verify-${payment.reference_number}`"
                                    @click="confirmReceived(payment)"
                                    >Received</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="busy === payment.id"
                                    @click="askReject(payment)"
                                    >Not received</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="rejectOpen">
        <DialogContent v-if="rejecting">
            <form class="grid gap-4" @submit.prevent="submitReject">
                <DialogHeader>
                    <DialogTitle
                        >Reference {{ rejecting.reference_number }} not
                        received?</DialogTitle
                    >
                    <DialogDescription>
                        Order {{ rejecting.order_number }} becomes unpaid ({{
                            formatMoney(rejecting.amount)
                        }}
                        owing). This is recorded in the audit log.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="reject-reason">What happened?</Label>
                    <textarea
                        id="reject-reason"
                        v-model="rejectForm.reason"
                        v-focus
                        rows="3"
                        maxlength="500"
                        required
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        placeholder="e.g. No transfer with this reference in the GCash app"
                    />
                    <InputError :message="rejectForm.errors.reason" />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Back</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            rejectForm.processing ||
                            rejectForm.reason.trim().length < 3
                        "
                        >Mark not received</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
