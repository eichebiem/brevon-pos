<script setup lang="ts">
import { toast } from 'vue-sonner';
import { router } from '@inertiajs/vue3';
import { Clock, MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatQuantity } from '@/lib/format';
import { advance } from '@/routes/orders';
import { show } from '@/routes/pos/orders';
import type { PrepCard } from '@/types/pos';

const props = defineProps<{
    order: PrepCard;
    now: number;
    canCancel: boolean;
}>();
defineEmits<{ cancel: [order: PrepCard] }>();

const busy = ref(false);

const action: Record<string, string> = {
    confirmed: 'Start preparing',
    preparing: 'Mark ready',
    ready: 'Handed over',
};

// Minutes since the sale, for spotting orders that wait too long.
const minutes = computed(() =>
    Math.max(
        0,
        Math.floor((props.now - Date.parse(props.order.created_at)) / 60000),
    ),
);
const late = computed(
    () => props.order.status !== 'ready' && minutes.value >= 15,
);

function moveOn(): void {
    busy.value = true;
    // `from` is the status on screen: if someone else already moved it, the server says so.
    router.post(
        advance(props.order.id).url,
        { from: props.order.status },
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not save.');
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-sm"
        :data-test="`prep-card-${order.order_number}`"
    >
        <header class="flex items-start justify-between gap-2">
            <div>
                <p
                    class="font-display text-3xl leading-none text-brevon-navy dark:text-brevon-cream"
                >
                    #{{ order.short_number }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ order.order_type }} · {{ order.order_number }}
                </p>
            </div>
            <div class="text-right text-sm">
                <p
                    v-if="order.pickup_at"
                    class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-900"
                >
                    Pickup {{ formatDateTime(order.pickup_at) }}
                </p>
                <p
                    :class="[
                        'mt-1 inline-flex items-center gap-1',
                        late
                            ? 'font-semibold text-red-700'
                            : 'text-muted-foreground',
                    ]"
                >
                    <Clock class="size-3.5" aria-hidden="true" />
                    {{ minutes }} min
                </p>
            </div>
        </header>

        <ul class="grid gap-2" aria-label="Items">
            <li
                v-for="(item, i) in order.items"
                :key="i"
                :class="[
                    'rounded-lg px-3 py-2',
                    item.made_to_order
                        ? 'bg-brevon-cream-dark dark:bg-brevon-navy-dark'
                        : 'bg-secondary/60',
                ]"
            >
                <p class="text-base font-semibold">
                    {{ formatQuantity(item.quantity) }} × {{ item.name }}
                    <span v-if="item.variant" class="font-normal"
                        >· {{ item.variant }}</span
                    >
                </p>
                <p v-if="item.options.length" class="text-sm">
                    + {{ item.options.join(', ') }}
                </p>
                <p
                    v-if="item.notes"
                    class="text-sm font-medium text-red-800 dark:text-red-300"
                >
                    “{{ item.notes }}”
                </p>
            </li>
        </ul>

        <p
            v-if="order.notes"
            class="rounded-lg border border-dashed border-border px-3 py-2 text-sm"
        >
            <span class="font-semibold">Note:</span> {{ order.notes }}
        </p>

        <footer class="mt-auto flex items-center gap-2">
            <Button
                v-if="order.next_status"
                size="lg"
                class="min-h-12 flex-1 text-base"
                :disabled="busy"
                :data-test="`prep-advance-${order.order_number}`"
                @click="moveOn"
            >
                <Spinner v-if="busy" />
                {{ action[order.status] }}
            </Button>
            <details class="relative">
                <summary
                    class="grid size-12 cursor-pointer list-none place-items-center rounded-lg border border-border hover:bg-secondary"
                    aria-label="More actions"
                >
                    <MoreHorizontal aria-hidden="true" />
                </summary>
                <div
                    class="absolute right-0 bottom-14 z-10 grid w-44 gap-1 rounded-lg border border-border bg-popover p-1 shadow-lg"
                >
                    <a
                        :href="show(order.id).url"
                        class="rounded-md px-3 py-2 text-sm hover:bg-secondary"
                        >Order details</a
                    >
                    <button
                        v-if="canCancel"
                        type="button"
                        class="rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50"
                        @click="$emit('cancel', order)"
                    >
                        Cancel order
                    </button>
                </div>
            </details>
        </footer>
    </article>
</template>
