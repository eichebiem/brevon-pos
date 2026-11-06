<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/format';
import { cancel } from '@/routes/orders';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    order: { id: number; order_number: string; grand_total?: string } | null;
}>();

// FR-052, D-123: a reason is required; the payment is refunded in full.
const form = useForm({ reason: '', return_stock: true });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    if (!props.order) {
        return;
    }
    form.post(cancel(props.order.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="order" class="sm:max-w-lg">
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle
                        >Cancel order {{ order.order_number }}?</DialogTitle
                    >
                    <DialogDescription>
                        The payment is refunded in full<template
                            v-if="order.grand_total"
                        >
                            ({{ formatMoney(order.grand_total) }})</template
                        >. Give cash back from the drawer that took it; send
                        GCash back from the shop's GCash account. This cannot be
                        undone.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="cancel-reason">Reason</Label>
                    <textarea
                        id="cancel-reason"
                        v-model="form.reason"
                        v-focus
                        rows="3"
                        maxlength="500"
                        required
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        placeholder="e.g. Customer changed their mind before preparation"
                        aria-describedby="cancel-reason-hint"
                    />
                    <p
                        id="cancel-reason-hint"
                        class="text-xs text-muted-foreground"
                    >
                        No customer names or phone numbers.
                    </p>
                    <InputError :message="form.errors.reason" />
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input
                        v-model="form.return_stock"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-brevon-navy"
                    />
                    <span
                        >Put the doughnuts and other ready-made items back in
                        stock
                        <span class="block text-xs text-muted-foreground"
                            >Untick if they were handed over, dropped or can't
                            be sold again.</span
                        ></span
                    >
                </label>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            >Keep order</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            form.processing || form.reason.trim().length < 3
                        "
                        data-test="cancel-order-confirm"
                    >
                        <Spinner v-if="form.processing" /> Cancel and refund
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
