<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import {
    create,
    edit,
    index,
    status as statusRoute,
} from '@/routes/admin/categories';

type Row = {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    sort_order: number;
    is_active: boolean;
    active_products: number;
};

defineProps<{ categories: Row[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Categories', href: index() }] },
});

const { can } = usePermissions();
const pending = ref<Row | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function askToggle(row: Row): void {
    pending.value = row;
    confirmOpen.value = true;
}

function confirmToggle(): void {
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
    <Head title="Categories" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Categories
                </h1>
                <p class="text-sm text-muted-foreground">
                    Group products on the POS. Sort order controls the tab
                    order.
                </p>
            </div>
            <Button v-if="can('products.create')" as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New category</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Categories
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Name
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Order
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Active products
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
                    <tr v-if="categories.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No categories yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in categories"
                        :key="row.id"
                        class="border-t border-border"
                    >
                        <td
                            class="px-4 py-3 font-medium"
                            :class="{ 'pl-10': row.parent_id }"
                        >
                            <span
                                v-if="row.parent_id"
                                class="text-muted-foreground"
                                aria-hidden="true"
                                >↳
                            </span>
                            {{ row.name }}
                        </td>
                        <td class="px-4 py-3">{{ row.sort_order }}</td>
                        <td class="px-4 py-3">{{ row.active_products }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="row.is_active ? 'success' : 'neutral'"
                                >{{
                                    row.is_active ? 'Active' : 'Archived'
                                }}</StatusBadge
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
                                <Button
                                    v-if="can('products.archive')"
                                    variant="ghost"
                                    size="sm"
                                    @click="askToggle(row)"
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

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`${pending?.is_active ? 'Archive' : 'Restore'} ${pending?.name}?`"
            :description="
                pending?.is_active
                    ? 'It disappears from the POS. Products already sold keep their history.'
                    : 'It becomes available for products again.'
            "
            :confirm-label="pending?.is_active ? 'Archive' : 'Restore'"
            :destructive="pending?.is_active"
            :processing="processing"
            @confirm="confirmToggle"
        />
    </div>
</template>
