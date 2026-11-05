<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    CircleOff,
    CircleCheck,
    Pencil,
    Plus,
    Search,
    Tag,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, selectClass } from '@/lib/format';
import {
    create,
    edit,
    index,
    posAvailability,
    prices,
    status as statusRoute,
} from '@/routes/admin/products';
import type { Paginated } from '@/types/auth';

type Row = {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    product_type: string;
    image_url: string | null;
    base_price: string | null;
    variants_count: number;
    locations: string[];
    is_active: boolean;
    is_available_for_pos: boolean;
};

const props = defineProps<{
    products: Paginated<Row>;
    filters: {
        search?: string;
        category?: number;
        status?: string;
        location?: number;
    };
    categories: { id: number; name: string }[];
    locations: { id: number; code: string; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Products', href: index() }] },
});

const { can } = usePermissions();

const form = reactive({
    search: props.filters.search ?? '',
    category: props.filters.category ? String(props.filters.category) : '',
    status: props.filters.status ?? '',
    location: props.filters.location ? String(props.filters.location) : '',
});

function applyFilters(): void {
    router.get(
        index().url,
        Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}

function resetFilters(): void {
    Object.assign(form, { search: '', category: '', status: '', location: '' });
    applyFilters();
}

function toggleAvailability(row: Row): void {
    router.patch(
        posAvailability(row.id).url,
        { is_available_for_pos: !row.is_available_for_pos },
        {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'Not changed.'),
        },
    );
}

const pending = ref<Row | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function askArchive(row: Row): void {
    pending.value = row;
    confirmOpen.value = true;
}

function confirmArchive(): void {
    if (!pending.value) {
        return;
    }

    router.patch(
        statusRoute(pending.value.id).url,
        { is_active: !pending.value.is_active },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'Not changed.'),
            onFinish: () => {
                processing.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Products" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Products
                </h1>
                <p class="text-sm text-muted-foreground">
                    Archived products disappear from the POS but stay in past
                    sales and reports.
                </p>
            </div>
            <Button v-if="can('products.create')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New product</Link
                >
            </Button>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4"
            role="search"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1">
                <label for="search" class="text-xs font-medium"
                    >Name or SKU</label
                >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input id="search" v-model="form.search" class="pl-8" />
                </div>
            </div>
            <div class="grid gap-1">
                <label for="category" class="text-xs font-medium"
                    >Category</label
                >
                <select
                    id="category"
                    v-model="form.category"
                    :class="selectClass"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="c in categories"
                        :key="c.id"
                        :value="String(c.id)"
                    >
                        {{ c.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="status" class="text-xs font-medium">Status</label>
                <select id="status" v-model="form.status" :class="selectClass">
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="unavailable">Marked unavailable</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label for="location" class="text-xs font-medium"
                    >Sold at</label
                >
                <select
                    id="location"
                    v-model="form.location"
                    :class="selectClass"
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
            <Button type="submit">Apply</Button>
            <Button type="button" variant="ghost" @click="resetFilters"
                >Reset</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Products
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Product
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Category
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Base price
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Sold at
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="products.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No products match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="row in products.data"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="row.image_url"
                                    :src="row.image_url"
                                    alt=""
                                    class="size-10 rounded-lg object-cover"
                                />
                                <span
                                    v-else
                                    class="size-10 rounded-lg bg-secondary"
                                    aria-hidden="true"
                                />
                                <div>
                                    <p class="font-medium">{{ row.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.sku
                                        }}<template v-if="row.variants_count">
                                            ·
                                            {{ row.variants_count }}
                                            variants</template
                                        >
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ row.category ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatMoney(row.base_price) }}
                        </td>
                        <td class="px-4 py-3">
                            {{ row.locations.join(', ') || 'Nowhere yet' }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge v-if="!row.is_active" tone="neutral"
                                >Archived</StatusBadge
                            >
                            <StatusBadge
                                v-else-if="!row.is_available_for_pos"
                                tone="warning"
                                >Unavailable</StatusBadge
                            >
                            <StatusBadge v-else tone="success"
                                >Active</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="can('products.update')"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="edit(row.id)"
                                        ><Pencil aria-hidden="true" /> Edit<span
                                            class="sr-only"
                                        >
                                            {{ row.name }}</span
                                        ></Link
                                    >
                                </Button>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="prices(row.id)"
                                        ><Tag aria-hidden="true" /> Prices<span
                                            class="sr-only"
                                        >
                                            for {{ row.name }}</span
                                        ></Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        can('products.update') && row.is_active
                                    "
                                    variant="ghost"
                                    size="sm"
                                    @click="toggleAvailability(row)"
                                >
                                    <component
                                        :is="
                                            row.is_available_for_pos
                                                ? CircleOff
                                                : CircleCheck
                                        "
                                        aria-hidden="true"
                                    />
                                    {{
                                        row.is_available_for_pos
                                            ? 'Mark unavailable'
                                            : 'Mark available'
                                    }}<span class="sr-only">
                                        {{ row.name }}</span
                                    >
                                </Button>
                                <Button
                                    v-if="can('products.archive')"
                                    variant="ghost"
                                    size="sm"
                                    @click="askArchive(row)"
                                >
                                    <component
                                        :is="
                                            row.is_active
                                                ? Archive
                                                : ArchiveRestore
                                        "
                                        aria-hidden="true"
                                    />
                                    {{ row.is_active ? 'Archive' : 'Restore'
                                    }}<span class="sr-only">
                                        {{ row.name }}</span
                                    >
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="products" noun="products" />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`${pending?.is_active ? 'Archive' : 'Restore'} ${pending?.name}?`"
            :description="
                pending?.is_active
                    ? 'It is removed from the POS. Past sales, prices and reports are unchanged.'
                    : 'It can be sold again at the locations where it is enabled.'
            "
            :confirm-label="pending?.is_active ? 'Archive' : 'Restore'"
            :destructive="pending?.is_active"
            :processing="processing"
            @confirm="confirmArchive"
        />
    </div>
</template>
