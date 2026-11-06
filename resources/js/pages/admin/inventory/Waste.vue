<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import StockLinesTable from '@/components/inventory/StockLinesTable.vue';
import type { StockFormItem } from '@/components/inventory/StockLinesTable.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { stock, waste } from '@/routes/admin/inventory';
import { store } from '@/routes/admin/inventory/waste';

type Site = { id: number; code: string; name: string };

const props = defineProps<{
    site: Site | null;
    locations: Site[];
    items: StockFormItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stock on hand', href: stock() },
            { title: 'Record waste', href: waste() },
        ],
    },
});

const form = useForm({
    location_id: props.site?.id ?? 0,
    reason: '',
    lines: props.items.map((item) => ({
        inventory_item_id: item.id,
        quantity: '',
    })),
});

function changeLocation(event: Event): void {
    router.get(
        waste().url,
        { location: Number((event.target as HTMLSelectElement).value) },
        { replace: true },
    );
}

function submit(): void {
    form.post(store().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Record waste" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Record waste
            </h1>
            <p class="text-sm text-muted-foreground">
                Stock that can no longer be sold or used: dropped, spoiled,
                expired, or doughnuts left unsold at a pop-up. It is taken out
                of stock as soon as you save.
            </p>
        </header>

        <div
            class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-[auto_minmax(0,1fr)]"
        >
            <div class="grid gap-2">
                <Label for="location_id">Location</Label>
                <select
                    id="location_id"
                    :value="form.location_id"
                    :class="[selectClass, 'h-10']"
                    @change="changeLocation"
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
                    placeholder="e.g. Unsold at closing"
                />
                <InputError :message="form.errors.reason" />
            </div>
        </div>

        <StockLinesTable
            v-model:lines="form.lines"
            :items="items"
            :errors="form.errors as Record<string, string | undefined>"
            label="Wasted"
            name="waste"
        />

        <div class="flex gap-3">
            <Button
                type="submit"
                variant="destructive"
                :disabled="form.processing || !site"
                data-test="save-waste"
            >
                <Spinner v-if="form.processing" /> Write off
            </Button>
            <Button variant="outline" as-child>
                <Link :href="stock()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
