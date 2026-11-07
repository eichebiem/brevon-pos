<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import CashMovementForm from '@/components/shifts/CashMovementForm.vue';
import CashMovementList from '@/components/shifts/CashMovementList.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    selectClass,
} from '@/lib/format';
import { corrections, index, review } from '@/routes/admin/shifts';
import { close } from '@/routes/staff/shift';
import type {
    CashMovementRow,
    MovementType,
    ShiftRow,
    ShiftSummary,
} from '@/types/shifts';

const props = defineProps<{
    shift: ShiftRow & {
        location: string | null;
        location_code: string | null;
        business_date: string;
        closed_by: string | null;
        closing_notes: string | null;
        review_notes: string | null;
        cashier_id: number;
    };
    summary: ShiftSummary;
    movements: CashMovementRow[];
    movementTypes: MovementType[];
    can: {
        record: boolean;
        close: boolean;
        review: boolean;
        correct: boolean;
        decide: boolean;
    };
    me: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Shifts', href: index() }] },
});

const isOpen = computed(() => props.shift.status === 'open');
const corrected = computed(
    () =>
        props.summary.expected_correction !== '0.00' ||
        props.summary.counted_correction !== '0.00',
);

const closeForm = useForm({ counted_cash: '', closing_notes: '' });
const reviewForm = useForm({ review_notes: '' });
const correctionForm = useForm({
    corrects: 'expected',
    amount: '',
    reason: '',
});

function closeShift(): void {
    closeForm.post(close(props.shift.id).url, { preserveScroll: true });
}

function submitReview(): void {
    reviewForm.post(review(props.shift.id).url, { preserveScroll: true });
}

function submitCorrection(): void {
    correctionForm.post(corrections(props.shift.id).url, {
        preserveScroll: true,
        onSuccess: () => correctionForm.reset(),
    });
}

const tone = (value: string | null) =>
    value === null || Number(value) === 0
        ? 'success'
        : Number(value) > 0
          ? 'info'
          : 'danger';

const textareaClass =
    'rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring';
</script>

<template>
    <Head :title="`Shift ${shift.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Shift {{ shift.number }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ shift.cashier }} · {{ shift.location_code }} ·
                    {{ shift.location }} · {{ shift.terminal }} ·
                    {{ formatDate(shift.business_date) }}
                </p>
            </div>
            <StatusBadge v-if="isOpen" tone="info" data-test="shift-status"
                >Open</StatusBadge
            >
            <StatusBadge
                v-else-if="shift.reviewed_at"
                tone="success"
                data-test="shift-status"
                >Reviewed</StatusBadge
            >
            <StatusBadge
                v-else-if="summary.needs_review"
                tone="warning"
                data-test="shift-status"
                >Needs review</StatusBadge
            >
            <StatusBadge v-else tone="neutral" data-test="shift-status"
                >Closed</StatusBadge
            >
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <!-- Drawer figures (FR-077, FR-079, BR-008) -->
            <section
                class="rounded-xl border border-border bg-card p-5"
                aria-labelledby="drawer-heading"
            >
                <h2 id="drawer-heading" class="mb-3 font-semibold">
                    Cash drawer
                </h2>
                <dl class="grid gap-2 text-sm" data-test="drawer-figures">
                    <div class="flex justify-between">
                        <dt>Opening cash</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.opening_cash) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>+ Cash sales</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.cash_sales) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>+ Cash in</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.cash_in) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>− Cash out</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.cash_out) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>− Paid out</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.paid_out) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>− Cash refunds</dt>
                        <dd class="tabular-nums">
                            {{ formatMoney(summary.refunds) }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between border-t border-border pt-2 font-semibold"
                    >
                        <dt>
                            Expected cash<span
                                v-if="isOpen"
                                class="font-normal text-muted-foreground"
                            >
                                (so far)</span
                            >
                        </dt>
                        <dd class="tabular-nums" data-test="expected-cash">
                            {{ formatMoney(summary.expected_cash) }}
                        </dd>
                    </div>
                    <template v-if="!isOpen">
                        <div class="flex justify-between font-semibold">
                            <dt>Counted cash</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(summary.counted_cash) }}
                            </dd>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <dt>Difference</dt>
                            <dd>
                                <StatusBadge :tone="tone(summary.variance)">{{
                                    formatMoney(summary.variance)
                                }}</StatusBadge>
                            </dd>
                        </div>
                    </template>
                </dl>
                <p
                    v-if="summary.cancelled_cash !== '0.00'"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    {{ formatMoney(summary.cancelled_cash) }} of cash sales were
                    cancelled and refunded, and are not counted above.
                </p>

                <!-- Corrections never change the closing figures (FR-082, D-129) -->
                <div
                    v-if="corrected"
                    class="mt-4 rounded-lg border border-border bg-secondary/50 p-3"
                    data-test="corrected-figures"
                >
                    <h3 class="mb-2 text-sm font-semibold">
                        After corrections
                    </h3>
                    <dl class="grid gap-1 text-sm">
                        <div class="flex justify-between">
                            <dt>Expected cash</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(summary.corrected_expected) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Counted cash</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(summary.corrected_counted) }}
                            </dd>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <dt>Difference</dt>
                            <dd>
                                <StatusBadge
                                    :tone="tone(summary.corrected_variance)"
                                    >{{
                                        formatMoney(summary.corrected_variance)
                                    }}</StatusBadge
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <div class="grid gap-6">
                <!-- Non-cash takings, never in the drawer (FR-081, BR-007) -->
                <section
                    class="rounded-xl border border-border bg-card p-5"
                    aria-labelledby="noncash-heading"
                >
                    <h2 id="noncash-heading" class="mb-3 font-semibold">
                        Not in the drawer
                    </h2>
                    <p
                        v-if="summary.non_cash.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No GCash or other non-cash payments.
                    </p>
                    <dl v-else class="grid gap-2 text-sm">
                        <div
                            v-for="row in summary.non_cash"
                            :key="row.method"
                            class="flex justify-between gap-4"
                        >
                            <dt>{{ row.method }}</dt>
                            <dd class="text-right tabular-nums">
                                {{ formatMoney(row.verified) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-sm text-muted-foreground">
                        {{ summary.order_count }}
                        {{ summary.order_count === 1 ? 'order' : 'orders'
                        }}<template v-if="summary.cancelled_count"
                            >, {{ summary.cancelled_count }} cancelled</template
                        >.
                    </p>
                </section>

                <section
                    class="grid gap-2 rounded-xl border border-border bg-card p-5 text-sm"
                    aria-labelledby="times-heading"
                >
                    <h2 id="times-heading" class="font-semibold">Shift</h2>
                    <p>Opened {{ formatDateTime(shift.opened_at) }}</p>
                    <p v-if="shift.closed_at">
                        Closed {{ formatDateTime(shift.closed_at) }} by
                        {{ shift.closed_by }}
                    </p>
                    <p v-if="shift.closing_notes">
                        <span class="text-muted-foreground"
                            >Closing notes:</span
                        >
                        {{ shift.closing_notes }}
                    </p>
                    <p v-if="shift.reviewed_at" data-test="review-stamp">
                        Reviewed {{ formatDateTime(shift.reviewed_at) }} by
                        {{ shift.reviewed_by
                        }}<template v-if="shift.review_notes">
                            — {{ shift.review_notes }}</template
                        >
                    </p>
                </section>
            </div>
        </div>

        <!-- Movements (FR-076) -->
        <section
            class="grid grid-cols-[minmax(0,1fr)] gap-4 rounded-xl border border-border bg-card p-5"
            aria-labelledby="movements-heading"
        >
            <h2 id="movements-heading" class="font-semibold">
                Cash in and out
            </h2>
            <CashMovementForm
                v-if="can.record"
                :shift-id="shift.id"
                :types="movementTypes"
            />
            <div class="rounded-lg border border-border">
                <CashMovementList
                    :movements="movements"
                    :can-decide="can.decide"
                    :me="me"
                />
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <!-- A manager closes a forgotten shift -->
            <form
                v-if="can.close"
                class="grid gap-4 rounded-xl border border-border bg-card p-5"
                novalidate
                aria-labelledby="close-heading"
                @submit.prevent="closeShift"
            >
                <h2 id="close-heading" class="font-semibold">Close shift</h2>
                <p class="text-sm text-muted-foreground">
                    For a shift the cashier could not close. Count the drawer
                    and note why you closed it.
                </p>
                <div class="grid max-w-56 gap-2">
                    <Label for="counted_cash">Counted cash (₱)</Label>
                    <Input
                        id="counted_cash"
                        v-model="closeForm.counted_cash"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="0.00"
                    />
                    <InputError :message="closeForm.errors.counted_cash" />
                </div>
                <div class="grid gap-2">
                    <Label for="closing_notes">Notes</Label>
                    <textarea
                        id="closing_notes"
                        v-model="closeForm.closing_notes"
                        rows="2"
                        maxlength="1000"
                        :class="textareaClass"
                    />
                    <InputError :message="closeForm.errors.closing_notes" />
                </div>
                <div>
                    <Button
                        type="submit"
                        :disabled="closeForm.processing"
                        data-test="admin-close-shift"
                    >
                        <Spinner v-if="closeForm.processing" /> Close shift
                    </Button>
                </div>
            </form>

            <!-- Sign-off (FR-080, D-128) -->
            <form
                v-if="can.review"
                class="grid gap-4 rounded-xl border border-border bg-card p-5"
                novalidate
                aria-labelledby="review-heading"
                @submit.prevent="submitReview"
            >
                <h2 id="review-heading" class="font-semibold">Review shift</h2>
                <p class="text-sm text-muted-foreground">
                    Sign off the cash count. A shift with a difference needs a
                    note on how it was handled. A review is given once.
                </p>
                <div class="grid gap-2">
                    <Label for="review_notes">Notes</Label>
                    <textarea
                        id="review_notes"
                        v-model="reviewForm.review_notes"
                        rows="2"
                        maxlength="1000"
                        :class="textareaClass"
                    />
                    <InputError :message="reviewForm.errors.review_notes" />
                </div>
                <div>
                    <Button
                        type="submit"
                        :disabled="reviewForm.processing"
                        data-test="review-shift"
                    >
                        <Spinner v-if="reviewForm.processing" /> Mark reviewed
                    </Button>
                </div>
            </form>

            <!-- Corrections (FR-082, D-129) -->
            <form
                v-if="can.correct"
                class="grid gap-4 rounded-xl border border-border bg-card p-5"
                novalidate
                aria-labelledby="correction-heading"
                @submit.prevent="submitCorrection"
            >
                <h2 id="correction-heading" class="font-semibold">
                    Add a correction
                </h2>
                <p class="text-sm text-muted-foreground">
                    The closed shift is never edited. A correction is added
                    beside it, e.g. a paid-out that was not recorded (expected
                    −50.00) or a miscounted bundle (counted +100.00).
                </p>
                <div class="grid gap-4 sm:grid-cols-[auto_10rem]">
                    <div class="grid gap-2">
                        <Label for="corrects">Correct the</Label>
                        <select
                            id="corrects"
                            v-model="correctionForm.corrects"
                            :class="[selectClass, 'h-10']"
                        >
                            <option value="expected">Expected cash</option>
                            <option value="counted">Counted cash</option>
                        </select>
                        <InputError :message="correctionForm.errors.corrects" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correction_amount">Amount (₱)</Label>
                        <Input
                            id="correction_amount"
                            v-model="correctionForm.amount"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="-50.00"
                        />
                    </div>
                </div>
                <InputError :message="correctionForm.errors.amount" />
                <div class="grid gap-2">
                    <Label for="correction_reason">Reason</Label>
                    <textarea
                        id="correction_reason"
                        v-model="correctionForm.reason"
                        rows="2"
                        maxlength="500"
                        :class="textareaClass"
                    />
                    <InputError :message="correctionForm.errors.reason" />
                </div>
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="correctionForm.processing"
                        data-test="add-correction"
                    >
                        <Spinner v-if="correctionForm.processing" /> Add
                        correction
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>
