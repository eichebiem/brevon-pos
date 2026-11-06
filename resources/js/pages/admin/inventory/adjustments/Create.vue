<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import StockLinesTable from '@/components/inventory/StockLinesTable.vue';
import type { StockFormItem } from '@/components/inventory/StockLinesTable.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { create, index, store } from '@/routes/admin/inventory/adjustments';

type Site = { id: number; code: string; name: string };

const props = defineProps<{
    kind: 'count' | 'change';
    site: Site | null;
    locations: Site[];
    items: StockFormItem[];
    postsImmediately: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Counts & adjustments', href: index() },
            { title: 'New', href: create() },
        ],
    },
});

const form = useForm({
    kind: props.kind,
    location_id: props.site?.id ?? 0,
    reason: '',
    lines: props.items.map((item) => ({
        inventory_item_id: item.id,
        quantity: '',
    })),
});

function reload(kind: string, location: number): void {
    router.get(create().url, { kind, location }, { replace: true });
}

function submit(): void {
    form.post(store().url, { preserveScroll: true });
}

const errors = (): Record<string, string | undefined> =>
    form.errors as Record<string, string | undefined>;
</script>

<template>
    <Head :title="kind === 'count' ? 'Count stock' : 'Adjust stock'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ kind === 'count' ? 'Count stock' : 'Adjust stock' }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="kind === 'count'"
                    >Enter what is physically on the shelf. The difference from
                    the system is what gets posted, so keep selling while it
                    waits for approval.</template
                >
                <template v-else
                    >Add a quantity that was found, or remove one with a minus
                    sign, e.g. −2 for stock used for staff meals.</template
                >
                <template v-if="postsImmediately">
                    You can approve adjustments, so this posts as soon as you
                    save it.</template
                >
                <template v-else>
                    A manager approves it before stock changes.</template
                >
            </p>
        </header>

        <div
            class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-[auto_auto_minmax(0,1fr)]"
        >
            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Type</legend>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        :variant="kind === 'count' ? 'default' : 'outline'"
                        :aria-pressed="kind === 'count'"
                        @click="reload('count', form.location_id)"
                        >Count</Button
                    >
                    <Button
                        type="button"
                        :variant="kind === 'change' ? 'default' : 'outline'"
                        :aria-pressed="kind === 'change'"
                        data-test="kind-change"
                        @click="reload('change', form.location_id)"
                        >Add or remove</Button
                    >
                </div>
            </fieldset>
            <div class="grid gap-2">
                <Label for="location_id">Location</Label>
                <select
                    id="location_id"
                    :value="form.location_id"
                    :class="[selectClass, 'h-10']"
                    @change="
                        reload(
                            kind,
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="l in locations" :key="l.id" :value="l.id">
                        {{ l.code }} · {{ l.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="reason">Reason</Label>
                <input
                    id="reason"
                    v-model="form.reason"
                    maxlength="500"
                    class="h-10 rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    :placeholder="
                        kind === 'count'
                            ? 'e.g. End-of-week count'
                            : 'e.g. Box of cups found in storage'
                    "
                />
                <InputError :message="form.errors.reason" />
            </div>
        </div>

        <p
            v-if="!site"
            class="rounded-lg border border-border bg-secondary p-3 text-sm"
        >
            You have no location where stock can be counted.
        </p>
        <StockLinesTable
            v-else
            v-model:lines="form.lines"
            :items="items"
            :errors="errors()"
            :label="kind === 'count' ? 'Counted' : 'Add (+) or remove (−)'"
            :placeholder="kind === 'count' ? '' : '+0'"
            name="adjust"
        />

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing || !site"
                data-test="save-adjustment"
            >
                <Spinner v-if="form.processing" />
                {{ postsImmediately ? 'Save and post' : 'Send for approval' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
