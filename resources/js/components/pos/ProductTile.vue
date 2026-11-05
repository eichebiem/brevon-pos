<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatQuantity } from '@/lib/format';
import type { PosProduct } from '@/types/pos';

const props = defineProps<{ product: PosProduct; inCart: number }>();
defineEmits<{ pick: [product: PosProduct] }>();

// Stock shown for doughnuts sold from finished stock (display only; checkout re-checks).
const onHand = computed(() => props.product.on_hand);
const soldOut = computed(
    () => onHand.value !== null && Number(onHand.value) <= 0,
);
const lowStock = computed(
    () => onHand.value !== null && !soldOut.value && Number(onHand.value) <= 5,
);
const initials = computed(() =>
    props.product.name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <button
        type="button"
        class="group relative flex min-h-36 flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition hover:border-brevon-gold hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-55 disabled:hover:border-border disabled:hover:shadow-none"
        :disabled="soldOut"
        :aria-label="`${product.name}, ${product.prices_vary ? 'from ' : ''}${formatMoney(product.price)}${soldOut ? ', sold out' : ''}`"
        :data-test="`pos-product-${product.sku}`"
        @click="$emit('pick', product)"
    >
        <div
            class="relative flex h-20 items-center justify-center bg-brevon-cream-dark dark:bg-brevon-navy-dark"
        >
            <img
                v-if="product.image_url"
                :src="product.image_url"
                alt=""
                class="h-full w-full object-cover"
            />
            <span
                v-else
                class="font-display text-2xl text-brevon-navy/70 dark:text-brevon-cream/70"
                aria-hidden="true"
                >{{ initials }}</span
            >
            <span
                v-if="inCart > 0"
                class="absolute top-2 right-2 grid min-w-6 place-items-center rounded-full bg-brevon-navy px-1.5 text-xs font-semibold text-brevon-cream"
                aria-hidden="true"
                >{{ inCart }}</span
            >
        </div>
        <div class="flex flex-1 flex-col gap-1 p-3">
            <span class="line-clamp-2 text-sm leading-snug font-semibold">{{
                product.name
            }}</span>
            <span class="mt-auto flex items-end justify-between gap-2">
                <span
                    class="text-sm font-semibold text-brevon-navy dark:text-brevon-gold-light"
                    ><span
                        v-if="product.prices_vary"
                        class="text-xs font-normal text-muted-foreground"
                        >from </span
                    >{{ formatMoney(product.price) }}</span
                >
                <span
                    v-if="soldOut"
                    class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-900"
                    >Sold out</span
                >
                <span
                    v-else-if="onHand !== null"
                    :class="[
                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                        lowStock
                            ? 'bg-amber-100 text-amber-900'
                            : 'bg-secondary text-secondary-foreground',
                    ]"
                    >{{ formatQuantity(onHand) }} left</span
                >
            </span>
        </div>
    </button>
</template>
