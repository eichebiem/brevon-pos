<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatQuantity, formatUnitCost } from '@/lib/format';
import { cancel, edit, index, post } from '@/routes/admin/production';
import { batchTone, type BatchRow } from '@/types/production';

const props = defineProps<{
    batch: BatchRow & {
        location: { code: string; name: string } | null;
        unit: string | null;
        recipe_version: number | null;
        reject_reason: string | null;
        notes: string | null;
        unit_cost: string | null;
        posted_by: string | null;
        posted_at: string | null;
        cancelled_by: string | null;
        cancelled_at: string | null;
        cancel_reason: string | null;
        lines: {
            id: number;
            sku: string | null;
            name: string | null;
            unit: string | null;
            quantity: string;
            on_hand: string | null;
            unit_cost: string | null;
        }[];
    };
    can: { edit: boolean; post: boolean; cancel: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Production', href: index() }] },
});

const posting = ref(false);

function postBatch(): void {
    posting.value = true;
    router.post(
        post(props.batch.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not post.');
            },
            onFinish: () => {
                posting.value = false;
            },
        },
    );
}

const cancelOpen = ref(false);
const cancelForm = useForm({ reason: '' });
</script>

<template>
    <Head :title="`Batch ${batch.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1
                        class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                    >
                        {{ batch.number }}
                    </h1>
                    <StatusBadge
                        :tone="batchTone[batch.status]"
                        data-test="batch-status"
                        >{{ batch.status_label }}</StatusBadge
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ batch.product?.name }} · recipe version
                    {{ batch.recipe_version }} · {{ batch.location?.code }} · by
                    {{ batch.created_by }}
                    {{ formatDateTime(batch.created_at) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.edit" variant="outline" as-child>
                    <Link :href="edit(batch.id)"
                        ><Pencil aria-hidden="true" /> Edit</Link
                    >
                </Button>
                <Button
                    v-if="can.post"
                    :disabled="posting"
                    data-test="batch-post"
                    @click="postBatch"
                >
                    <Spinner v-if="posting" /> Post to stock
                </Button>
                <Button
                    v-if="can.cancel"
                    variant="outline"
                    class="text-red-700"
                    @click="cancelOpen = !cancelOpen"
                    >Cancel batch</Button
                >
            </div>
        </header>

        <dl class="grid max-w-3xl grid-cols-3 gap-3 text-sm">
            <div class="rounded-xl border border-border bg-card p-3">
                <dt class="text-xs text-muted-foreground">Planned</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ formatQuantity(batch.planned) }} {{ batch.unit }}
                </dd>
            </div>
            <div class="rounded-xl border border-border bg-card p-3">
                <dt class="text-xs text-muted-foreground">Good</dt>
                <dd
                    class="text-xl font-semibold tabular-nums"
                    data-test="batch-good"
                >
                    {{ formatQuantity(batch.actual) }} {{ batch.unit }}
                </dd>
            </div>
            <div class="rounded-xl border border-border bg-card p-3">
                <dt class="text-xs text-muted-foreground">Rejected</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ formatQuantity(batch.rejected) }} {{ batch.unit }}
                </dd>
                <dd
                    v-if="batch.reject_reason"
                    class="text-xs text-muted-foreground"
                >
                    {{ batch.reject_reason }}
                </dd>
            </div>
        </dl>

        <p v-if="batch.notes" class="max-w-3xl text-sm">
            <span class="text-muted-foreground">Notes:</span> {{ batch.notes }}
        </p>
        <p v-if="batch.posted_at" class="text-sm" data-test="batch-posted">
            Posted by {{ batch.posted_by }}
            {{ formatDateTime(batch.posted_at) }} · cost per piece
            {{
                batch.unit_cost === null
                    ? 'unknown (an ingredient has no cost)'
                    : formatUnitCost(batch.unit_cost)
            }}
        </p>
        <section
            v-if="batch.cancelled_at"
            class="max-w-3xl rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"
        >
            Cancelled {{ formatDateTime(batch.cancelled_at) }} by
            {{ batch.cancelled_by }}: {{ batch.cancel_reason }}
        </section>

        <form
            v-if="cancelOpen"
            class="grid max-w-xl gap-3 rounded-xl border border-border bg-card p-5"
            novalidate
            @submit.prevent="
                cancelForm.post(cancel(batch.id).url, {
                    preserveScroll: true,
                    onSuccess: () => (cancelOpen = false),
                })
            "
        >
            <Label for="cancel-reason">Why cancel this batch?</Label>
            <textarea
                id="cancel-reason"
                v-model="cancelForm.reason"
                rows="2"
                maxlength="500"
                class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
            />
            <InputError :message="cancelForm.errors.reason" />
            <div class="flex gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    :disabled="
                        cancelForm.processing ||
                        cancelForm.reason.trim().length < 3
                    "
                    >Cancel batch</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    @click="cancelOpen = false"
                    >Keep it</Button
                >
            </div>
        </form>

        <div
            class="max-w-3xl overflow-x-auto rounded-xl border border-border bg-card"
        >
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Ingredients used
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Ingredient
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Used
                        </th>
                        <th
                            v-if="batch.status === 'draft'"
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            On hand now
                        </th>
                        <th
                            v-else-if="batch.status === 'posted'"
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Cost per unit
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in batch.lines"
                        :key="line.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ line.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ line.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatQuantity(line.quantity) }} {{ line.unit }}
                        </td>
                        <td
                            v-if="batch.status === 'draft'"
                            class="px-4 py-3 text-right tabular-nums"
                            :class="
                                Number(line.on_hand) < Number(line.quantity)
                                    ? 'font-semibold text-destructive'
                                    : ''
                            "
                        >
                            {{ formatQuantity(line.on_hand) }} {{ line.unit }}
                        </td>
                        <td
                            v-else-if="batch.status === 'posted'"
                            class="px-4 py-3 text-right tabular-nums"
                        >
                            {{ formatUnitCost(line.unit_cost) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
