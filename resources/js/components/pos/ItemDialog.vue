<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import { formatMoney, formatQuantity } from '@/lib/format';
import type { PosProduct } from '@/types/pos';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ product: PosProduct | null }>();
const emit = defineEmits<{
    add: [
        line: {
            variant_id: number | null;
            quantity: number;
            notes: string;
        },
    ];
}>();

const variantId = ref<number | null>(null);
const quantity = ref(1);
const notes = ref('');

watch(
    () => [open.value, props.product] as const,
    ([isOpen, product]) => {
        if (!isOpen || !product) {
            return;
        }
        variantId.value = product.variants[0]?.id ?? null;
        quantity.value = 1;
        notes.value = '';
    },
    { immediate: true },
);

function add(): void {
    emit('add', {
        variant_id: variantId.value,
        quantity: quantity.value,
        notes: notes.value.trim(),
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="product"
            class="max-h-[90svh] overflow-y-auto sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">{{
                    product.name
                }}</DialogTitle>
                <DialogDescription
                    >Choose the size and quantity.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="add">
                <fieldset v-if="product.variants.length" class="grid gap-2">
                    <legend class="mb-2 text-sm font-semibold">Size</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label
                            v-for="variant in product.variants"
                            :key="variant.id"
                            :class="[
                                'flex min-h-14 cursor-pointer items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm',
                                variantId === variant.id
                                    ? 'border-brevon-navy bg-brevon-navy text-brevon-cream'
                                    : 'border-border hover:border-brevon-gold',
                            ]"
                        >
                            <input
                                v-model="variantId"
                                type="radio"
                                name="variant"
                                :value="variant.id"
                                class="sr-only"
                            />
                            <span class="font-medium">{{ variant.name }}</span>
                            <span class="text-right">
                                {{ formatMoney(variant.price) }}
                                <span
                                    v-if="variant.on_hand !== null"
                                    class="block text-xs opacity-80"
                                    >{{
                                        formatQuantity(variant.on_hand)
                                    }}
                                    left</span
                                >
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <Label for="item-qty">Quantity</Label>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-11"
                            aria-label="One less"
                            :disabled="quantity <= 1"
                            @click="quantity--"
                            ><Minus
                        /></Button>
                        <Input
                            id="item-qty"
                            v-model.number="quantity"
                            type="number"
                            min="1"
                            max="999"
                            inputmode="numeric"
                            class="h-11 w-20 text-center text-lg"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-11"
                            aria-label="One more"
                            :disabled="quantity >= 999"
                            @click="quantity++"
                            ><Plus
                        /></Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="item-notes">Instructions for this item</Label>
                    <Input
                        id="item-notes"
                        v-model="notes"
                        maxlength="255"
                        placeholder="e.g. less ice"
                        aria-describedby="item-notes-hint"
                    />
                    <p
                        id="item-notes-hint"
                        class="text-xs text-muted-foreground"
                    >
                        How to make it — no customer names or phone numbers.
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        size="lg"
                        class="min-h-12 w-full sm:w-auto"
                        :disabled="quantity < 1 || quantity > 999"
                        data-test="pos-item-add"
                        >Add to order</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
