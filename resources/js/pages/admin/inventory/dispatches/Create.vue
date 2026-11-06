<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import StockLinesTable from '@/components/inventory/StockLinesTable.vue';
import type { StockFormItem } from '@/components/inventory/StockLinesTable.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { create, index, store } from '@/routes/admin/inventory/dispatches';

type Site = { id: number; code: string; name: string };

const props = defineProps<{
    from: Site;
    worksAtMain: boolean;
    destinations: (Site & { status: string })[];
    items: StockFormItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dispatches', href: index() },
            { title: 'New dispatch', href: create() },
        ],
    },
});

const form = useForm({
    to_branch_id:
        props.destinations.length === 1 ? String(props.destinations[0].id) : '',
    notes: '',
    lines: props.items.map((item) => ({
        inventory_item_id: item.id,
        quantity: '',
    })),
});

function submit(): void {
    form.post(store().url, { preserveScroll: true });
}

const statusLabel: Record<string, string> = {
    operating: 'open today',
    scheduled: 'opens later',
};
</script>

<template>
    <Head title="New dispatch" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Dispatch doughnuts
            </h1>
            <p class="text-sm text-muted-foreground">
                From {{ from.code }} · {{ from.name }}. Stock leaves the main
                branch and arrives at the pop-up as soon as you save; there is
                no separate receiving step.
            </p>
        </header>

        <p
            v-if="!worksAtMain || destinations.length === 0"
            class="rounded-lg border border-border bg-secondary p-3 text-sm"
            role="note"
        >
            <template v-if="!worksAtMain"
                >You need to be assigned to the main branch to send
                dispatches.</template
            >
            <template v-else
                >There is no active pop-up you are assigned to. Ask an owner to
                assign you or add a pop-up under Locations.</template
            >
        </p>

        <template v-else>
            <div
                class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-[auto_minmax(0,1fr)]"
            >
                <div class="grid gap-2">
                    <Label for="to_branch_id">To pop-up</Label>
                    <select
                        id="to_branch_id"
                        v-model="form.to_branch_id"
                        :class="[selectClass, 'h-10']"
                    >
                        <option value="" disabled>Choose a pop-up…</option>
                        <option
                            v-for="d in destinations"
                            :key="d.id"
                            :value="String(d.id)"
                        >
                            {{ d.code }} · {{ d.name }} ({{
                                statusLabel[d.status] ?? d.status
                            }})
                        </option>
                    </select>
                    <InputError :message="form.errors.to_branch_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="notes">Notes (optional)</Label>
                    <input
                        id="notes"
                        v-model="form.notes"
                        maxlength="500"
                        class="h-10 rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                        placeholder="e.g. Morning delivery, driver Jun"
                    />
                </div>
            </div>

            <StockLinesTable
                v-model:lines="form.lines"
                :items="items"
                :errors="form.errors as Record<string, string | undefined>"
                label="Send"
                name="dispatch"
            />
        </template>

        <div class="flex gap-3">
            <Button
                v-if="worksAtMain && destinations.length"
                type="submit"
                :disabled="form.processing"
                data-test="save-dispatch"
            >
                <Spinner v-if="form.processing" /> Send dispatch
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
