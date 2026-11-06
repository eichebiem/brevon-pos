<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CancelOrderDialog from '@/components/orders/CancelOrderDialog.vue';
import PrepOrderCard from '@/components/orders/PrepOrderCard.vue';
import type { PrepBoard, PrepCard } from '@/types/pos';

const props = defineProps<{
    site: { code: string; name: string } | null;
    board: PrepBoard | null;
    now: string;
    canCancel: boolean;
}>();

// New orders appear without anyone touching the screen (FR-047).
usePoll(8000, { only: ['board', 'now'] });

// A local clock for the "minutes waiting" labels between refreshes.
const clock = ref(Date.parse(props.now));
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => (clock.value = Date.now()), 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const columns = computed(() => [
    {
        key: 'new',
        title: 'New',
        hint: 'Paid and waiting',
        orders: props.board?.new ?? [],
    },
    {
        key: 'preparing',
        title: 'Preparing',
        hint: 'Being made',
        orders: props.board?.preparing ?? [],
    },
    {
        key: 'ready',
        title: 'Ready',
        hint: 'Waiting for pickup',
        orders: props.board?.ready ?? [],
    },
]);

const cancelOpen = ref(false);
const cancelling = ref<PrepCard | null>(null);
function askCancel(order: PrepCard): void {
    cancelling.value = order;
    cancelOpen.value = true;
}
</script>

<template>
    <Head title="Preparation" />

    <div v-if="!site" class="grid flex-1 place-items-center p-6 text-center">
        <p class="text-muted-foreground">
            You are not assigned to an operating location today.
        </p>
    </div>

    <div v-else class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
        <div class="grid items-start gap-4 lg:grid-cols-3">
            <section
                v-for="column in columns"
                :key="column.key"
                class="flex min-h-48 flex-col gap-3 self-stretch rounded-2xl bg-secondary/50 p-3"
                :aria-labelledby="`col-${column.key}`"
                :data-test="`prep-column-${column.key}`"
            >
                <h2
                    :id="`col-${column.key}`"
                    class="flex items-baseline justify-between px-1"
                >
                    <span class="font-display text-2xl">{{
                        column.title
                    }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ column.orders.length }} · {{ column.hint }}</span
                    >
                </h2>
                <p
                    v-if="column.orders.length === 0"
                    class="px-1 py-6 text-center text-sm text-muted-foreground"
                >
                    Nothing here.
                </p>
                <PrepOrderCard
                    v-for="order in column.orders"
                    :key="order.id"
                    :order="order"
                    :now="clock"
                    :can-cancel="canCancel"
                    @cancel="askCancel"
                />
            </section>
        </div>

        <section
            v-if="board && board.scheduled.length"
            class="rounded-2xl border border-border p-3"
            aria-labelledby="col-scheduled"
            data-test="prep-column-scheduled"
        >
            <h2 id="col-scheduled" class="mb-3 px-1">
                <span class="font-display text-2xl">Scheduled</span>
                <span class="ml-2 text-sm text-muted-foreground"
                    >Advance pickups — they move to New an hour before
                    pickup</span
                >
            </h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <PrepOrderCard
                    v-for="order in board.scheduled"
                    :key="order.id"
                    :order="order"
                    :now="clock"
                    :can-cancel="canCancel"
                    @cancel="askCancel"
                />
            </div>
        </section>
    </div>

    <CancelOrderDialog v-model:open="cancelOpen" :order="cancelling" />
</template>
