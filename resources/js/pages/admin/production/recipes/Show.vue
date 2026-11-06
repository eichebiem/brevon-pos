<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatQuantity } from '@/lib/format';
import { create, index } from '@/routes/admin/recipes';

type Version = {
    id: number;
    size: string | null;
    size_id: number | null;
    version: number;
    makes: string;
    unit: string;
    is_active: boolean;
    effective_from: string;
    effective_until: string | null;
    notes: string | null;
    created_by: string | null;
    lines: {
        sku: string | null;
        name: string | null;
        quantity: string;
        unit: string | null;
        wastage_percent: string;
    }[];
};

defineProps<{
    product: {
        id: number;
        sku: string;
        name: string;
        at_sale: boolean;
        sizes: { id: number; name: string }[];
    };
    versions: Version[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Recipes', href: index() }] },
});
</script>

<template>
    <Head :title="`Recipes: ${product.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ product.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ product.sku }} ·
                    {{
                        product.at_sale
                            ? 'ingredients taken at each sale'
                            : 'ingredients taken when a batch is posted'
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child>
                    <Link :href="create(product.id)">{{
                        product.at_sale
                            ? 'Change (every size)'
                            : 'Change recipe'
                    }}</Link>
                </Button>
                <Button
                    v-for="s in product.sizes"
                    :key="s.id"
                    variant="outline"
                    as-child
                >
                    <Link :href="create(product.id, { query: { size: s.id } })"
                        >{{ s.name }} only</Link
                    >
                </Button>
            </div>
        </header>
        <p
            v-if="product.at_sale && product.sizes.length"
            class="-mt-3 text-sm text-muted-foreground"
        >
            A size with its own recipe uses it; other sizes use the recipe for
            every size.
        </p>

        <section
            v-for="v in versions"
            :key="v.id"
            class="max-w-3xl rounded-xl border border-border bg-card p-4"
            :aria-label="`Version ${v.version}`"
        >
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">
                    {{ v.size ?? (product.at_sale ? 'Every size' : 'Recipe') }}
                    · version {{ v.version }}
                </h2>
                <StatusBadge :tone="v.is_active ? 'success' : 'neutral'">{{
                    v.is_active ? 'In use' : 'Replaced'
                }}</StatusBadge>
            </div>
            <p class="text-xs text-muted-foreground">
                <template v-if="!product.at_sale"
                    >Makes {{ formatQuantity(v.makes) }}
                    {{ v.unit }} · </template
                >From {{ formatDateTime(v.effective_from)
                }}<template v-if="v.effective_until">
                    to {{ formatDateTime(v.effective_until) }}</template
                >
                · by {{ v.created_by }}
            </p>
            <p v-if="v.notes" class="mt-1 text-sm">{{ v.notes }}</p>
            <ul class="mt-2 grid gap-1 text-sm">
                <li
                    v-for="l in v.lines"
                    :key="l.sku ?? ''"
                    class="flex justify-between gap-4 border-b border-border pb-1 last:border-0"
                >
                    <span>{{ l.name }}</span>
                    <span class="tabular-nums"
                        >{{ formatQuantity(l.quantity) }} {{ l.unit
                        }}<template v-if="Number(l.wastage_percent) > 0">
                            + {{ Number(l.wastage_percent) }}%</template
                        ></span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
