<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { formatDateTime, formatQuantity } from '@/lib/format';
import { index } from '@/routes/admin/inventory/dispatches';

defineProps<{
    dispatch: {
        id: number;
        number: string;
        from: { code: string; name: string } | null;
        to: { code: string; name: string } | null;
        posted_at: string;
        by: string | null;
        notes: string | null;
        lines: {
            id: number;
            sku: string | null;
            name: string | null;
            quantity: string;
        }[];
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dispatches', href: index() }] },
});
</script>

<template>
    <Head :title="`Dispatch ${dispatch.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Dispatch {{ dispatch.number }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ dispatch.from?.code }} → {{ dispatch.to?.code }} ·
                {{ dispatch.to?.name }} · sent
                {{ formatDateTime(dispatch.posted_at) }} by {{ dispatch.by }}
            </p>
            <p v-if="dispatch.notes" class="mt-1 text-sm">
                {{ dispatch.notes }}
            </p>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Dispatched items
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Item
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-3 text-right font-semibold"
                        >
                            Quantity
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in dispatch.lines"
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
                            {{ formatQuantity(line.quantity) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
