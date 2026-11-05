<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney, selectClass } from '@/lib/format';
import { end } from '@/routes/admin/product-prices';
import { index } from '@/routes/admin/products';
import { store } from '@/routes/admin/products/prices';

type PriceRow = {
    id: number;
    price: string;
    variant: string | null;
    location: string | null;
    effective_from: string;
    effective_until: string | null;
    is_current: boolean;
    is_base: boolean;
    created_by: string | null;
};

const props = defineProps<{
    product: { id: number; name: string; sku: string; is_active: boolean };
    prices: PriceRow[];
    variants: { id: number; name: string; is_active: boolean }[];
    locations: { id: number; code: string; name: string }[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Products', href: index() }] },
});

const current = computed(() => props.prices.filter((p) => p.is_current));

const form = useForm({ price: '', variant_id: '', branch_id: '' });

function submit(): void {
    form.transform((data) => ({
        price: data.price,
        variant_id: data.variant_id === '' ? null : Number(data.variant_id),
        branch_id: data.branch_id === '' ? null : Number(data.branch_id),
    })).post(store(props.product.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('price'),
    });
}

const scope = (row: PriceRow): string =>
    [row.variant ?? 'All variants', row.location ?? 'All locations'].join(
        ' · ',
    );

const ending = ref<PriceRow | null>(null);
const endOpen = ref(false);
const endProcessing = ref(false);

function askEnd(row: PriceRow): void {
    ending.value = row;
    endOpen.value = true;
}

function confirmEnd(): void {
    if (!ending.value) {
        return;
    }

    router.post(
        end(ending.value.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (endProcessing.value = true),
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'Not changed.'),
            onFinish: () => {
                endProcessing.value = false;
                endOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Prices · ${product.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Prices · {{ product.name }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ product.sku }}. A new price applies to sales from the moment
                it is saved; past sales keep the price they were sold at. The
                most specific price wins: variant + location, then variant, then
                location, then the base price.
            </p>
        </header>

        <section
            class="grid gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="current-heading"
        >
            <h2 id="current-heading" class="font-semibold">Current prices</h2>
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="row in current"
                    :key="row.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-border p-3"
                >
                    <div>
                        <p class="text-lg font-semibold">
                            {{ formatMoney(row.price) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ row.is_base ? 'Base price' : scope(row) }}
                        </p>
                    </div>
                    <Button
                        v-if="canManage && !row.is_base"
                        variant="ghost"
                        size="sm"
                        @click="askEnd(row)"
                        >Remove<span class="sr-only">
                            {{ scope(row) }} price</span
                        ></Button
                    >
                </li>
            </ul>
        </section>

        <form
            v-if="canManage"
            class="grid gap-4 rounded-xl border border-border bg-card p-5 md:grid-cols-[10rem_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end"
            aria-labelledby="set-heading"
            @submit.prevent="submit"
        >
            <h2 id="set-heading" class="font-semibold md:col-span-4">
                Set a new price
            </h2>
            <div class="grid gap-2">
                <Label for="price">Price (₱)</Label>
                <Input
                    id="price"
                    v-model="form.price"
                    required
                    inputmode="decimal"
                    placeholder="45.00"
                />
            </div>
            <div class="grid min-w-0 gap-2">
                <Label for="variant">Variant</Label>
                <select
                    id="variant"
                    v-model="form.variant_id"
                    :class="[selectClass, 'w-full min-w-0']"
                >
                    <option value="">All variants (base)</option>
                    <option
                        v-for="v in variants"
                        :key="v.id"
                        :value="String(v.id)"
                    >
                        {{ v.name }}{{ v.is_active ? '' : ' (inactive)' }}
                    </option>
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <Label for="location">Location</Label>
                <select
                    id="location"
                    v-model="form.branch_id"
                    :class="[selectClass, 'w-full min-w-0']"
                >
                    <option value="">All locations</option>
                    <option
                        v-for="l in locations"
                        :key="l.id"
                        :value="String(l.id)"
                    >
                        {{ l.code }} · {{ l.name }}
                    </option>
                </select>
            </div>
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" /> Save price
            </Button>
            <InputError
                class="md:col-span-4"
                :message="
                    form.errors.price ||
                    form.errors.variant_id ||
                    form.errors.branch_id
                "
            />
        </form>

        <section
            class="overflow-x-auto rounded-xl border border-border bg-card"
            aria-labelledby="history-heading"
        >
            <h2 id="history-heading" class="px-5 pt-5 font-semibold">
                History
            </h2>
            <table class="mt-3 w-full text-left text-sm">
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Price
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Applies to
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            From
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Until
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Set by
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in prices"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3 font-medium whitespace-nowrap">
                            {{ formatMoney(row.price) }}
                        </td>
                        <td class="px-4 py-3">{{ scope(row) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(row.effective_from) }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <StatusBadge v-if="row.is_current" tone="success"
                                >Current</StatusBadge
                            >
                            <template v-else>{{
                                formatDateTime(row.effective_until)
                            }}</template>
                        </td>
                        <td class="px-4 py-3">{{ row.created_by ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div>
            <Button variant="outline" as-child>
                <Link :href="index()">Back to products</Link>
            </Button>
        </div>

        <ConfirmDialog
            v-model:open="endOpen"
            title="Remove this special price?"
            :description="`${ending ? scope(ending) : ''} will use the next price in line from now on. The history is kept.`"
            confirm-label="Remove price"
            :processing="endProcessing"
            @confirm="confirmEnd"
        />
    </div>
</template>
