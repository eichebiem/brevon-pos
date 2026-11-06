<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatQuantity, selectClass } from '@/lib/format';
import { index, show, store, update } from '@/routes/admin/production';

type RecipeLine = {
    inventory_item_id: number;
    sku: string | null;
    name: string | null;
    unit: string | null;
    precision: number;
    quantity: string;
    wastage_percent: string;
    on_hand: string;
};

type ProductChoice = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    recipe: { version: number; makes: string; lines: RecipeLine[] } | null;
};

const props = defineProps<{
    batch: {
        id: number;
        number: string;
        product_id: number;
        planned: string;
        actual: string;
        rejected: string;
        reject_reason: string | null;
        notes: string | null;
        usage: Record<string, string>;
    } | null;
    products: ProductChoice[];
    canPost: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Production', href: index() }] },
});

const form = useForm({
    product_id: props.batch ? String(props.batch.product_id) : '',
    planned: props.batch?.planned ?? '',
    actual: props.batch?.actual ?? '',
    rejected: props.batch?.rejected ?? '',
    reject_reason: props.batch?.reject_reason ?? '',
    notes: props.batch?.notes ?? '',
    usage: { ...props.batch?.usage } as Record<string, string>,
});

const posting = ref(false);

const product = computed(() =>
    props.products.find((p) => String(p.id) === form.product_id),
);

const made = computed(
    () => (Number(form.actual) || 0) + (Number(form.rejected) || 0),
);

/** What the recipe says for the pieces made; the server works it out again. */
function recipeAmount(line: RecipeLine): string {
    const makes = Number(product.value?.recipe?.makes ?? 1);
    const exact =
        (Number(line.quantity) *
            (1 + Number(line.wastage_percent) / 100) *
            made.value) /
        makes;
    const factor = 10 ** line.precision;

    return String(Math.round(exact * factor) / factor);
}

// On edit, keep only the amounts that differ from the recipe, so changing
// the good or rejected count still recalculates the rest.
for (const line of product.value?.recipe?.lines ?? []) {
    const saved = form.usage[line.inventory_item_id];
    if (saved !== undefined && Number(saved) === Number(recipeAmount(line))) {
        delete form.usage[line.inventory_item_id];
    }
}

function changeProduct(): void {
    form.usage = {};
}

function submit(post: boolean): void {
    posting.value = post;
    form.transform((data) => ({ ...data, post }));
    if (props.batch) {
        form.put(update(props.batch.id).url);
    } else {
        form.post(store().url);
    }
}

const errors = computed(() => form.errors as Record<string, string>);
</script>

<template>
    <Head :title="batch ? `Edit ${batch.number}` : 'New production batch'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="submit(false)"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{
                    batch
                        ? `Edit batch ${batch.number}`
                        : 'New production batch'
                }}
            </h1>
            <p class="text-sm text-muted-foreground">
                Doughnuts made at the main branch. Posting takes the ingredients
                out of stock and adds the good pieces; rejects are recorded but
                not added.
            </p>
        </header>

        <section
            class="grid max-w-3xl gap-5 rounded-xl border border-border bg-card p-5 sm:grid-cols-2"
        >
            <div class="grid gap-2 sm:col-span-2">
                <Label for="product_id">Doughnut</Label>
                <select
                    id="product_id"
                    v-model="form.product_id"
                    :class="[selectClass, 'h-10']"
                    data-test="batch-product"
                    @change="changeProduct"
                >
                    <option value="" disabled>Choose a doughnut</option>
                    <option
                        v-for="p in products"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }} ({{ p.sku }})
                    </option>
                </select>
                <p
                    v-if="products.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No doughnut has a recipe yet. Add one under Recipes.
                </p>
                <p
                    v-else-if="product?.recipe"
                    class="text-xs text-muted-foreground"
                >
                    Recipe version {{ product.recipe.version }} · makes
                    {{ formatQuantity(product.recipe.makes) }}
                    {{ product.unit }}
                </p>
                <InputError :message="form.errors.product_id" />
            </div>
            <div class="grid gap-2">
                <Label for="planned">Planned</Label>
                <Input
                    id="planned"
                    v-model="form.planned"
                    inputmode="numeric"
                    data-test="batch-planned"
                />
                <InputError :message="form.errors.planned" />
            </div>
            <div class="grid gap-2">
                <Label for="actual">Came out good</Label>
                <Input
                    id="actual"
                    v-model="form.actual"
                    inputmode="numeric"
                    data-test="batch-actual"
                />
                <InputError :message="form.errors.actual" />
            </div>
            <div class="grid gap-2">
                <Label for="rejected">Rejected (optional)</Label>
                <Input
                    id="rejected"
                    v-model="form.rejected"
                    inputmode="numeric"
                    data-test="batch-rejected"
                />
                <InputError :message="form.errors.rejected" />
            </div>
            <div class="grid gap-2">
                <Label for="reject_reason">Why rejected</Label>
                <Input
                    id="reject_reason"
                    v-model="form.reject_reason"
                    maxlength="500"
                    :disabled="!(Number(form.rejected) > 0)"
                    placeholder="e.g. burnt, misshapen"
                    data-test="batch-reject-reason"
                />
                <InputError :message="form.errors.reject_reason" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">Notes (optional)</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="1000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
            </div>
        </section>

        <section
            v-if="product?.recipe"
            class="grid max-w-3xl gap-3 rounded-xl border border-border bg-card p-5"
            aria-labelledby="usage-heading"
        >
            <h2 id="usage-heading" class="font-semibold">Ingredients used</h2>
            <p class="text-sm text-muted-foreground">
                Worked out from the recipe for
                {{ formatQuantity(String(made)) }} {{ product.unit }} (good +
                rejected). Type an amount only if a different amount was used.
            </p>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                        <th
                            scope="col"
                            class="py-2 pr-3 text-right font-medium"
                        >
                            Recipe
                        </th>
                        <th scope="col" class="py-2 pr-3 font-medium">
                            Actually used
                        </th>
                        <th scope="col" class="py-2 text-right font-medium">
                            On hand
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in product.recipe.lines"
                        :key="line.inventory_item_id"
                        class="border-t border-border align-top"
                    >
                        <td class="py-2 pr-3">
                            <p class="font-medium">{{ line.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ line.sku }}
                            </p>
                        </td>
                        <td
                            class="py-2 pr-3 text-right tabular-nums"
                            :data-test="`usage-recipe-${line.sku}`"
                        >
                            {{ formatQuantity(recipeAmount(line)) }}
                            {{ line.unit }}
                        </td>
                        <td class="py-2 pr-3">
                            <Input
                                v-model="form.usage[line.inventory_item_id]"
                                class="w-28"
                                inputmode="decimal"
                                autocomplete="off"
                                :placeholder="recipeAmount(line)"
                                :aria-label="`Amount of ${line.name} actually used`"
                                :data-test="`usage-${line.sku}`"
                            />
                            <InputError
                                :message="
                                    errors[`usage.${line.inventory_item_id}`]
                                "
                            />
                        </td>
                        <td
                            class="py-2 text-right tabular-nums"
                            :class="
                                Number(line.on_hand) <
                                Number(
                                    form.usage[line.inventory_item_id] ||
                                        recipeAmount(line),
                                )
                                    ? 'font-semibold text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ formatQuantity(line.on_hand) }} {{ line.unit }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <InputError :message="errors.batch" />

        <div class="flex flex-wrap gap-3">
            <Button
                type="submit"
                variant="outline"
                :disabled="form.processing"
                data-test="save-batch"
            >
                <Spinner v-if="form.processing && !posting" /> Save draft
            </Button>
            <Button
                v-if="canPost"
                type="button"
                :disabled="form.processing"
                data-test="post-batch"
                @click="submit(true)"
            >
                <Spinner v-if="form.processing && posting" /> Save and post to
                stock
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="batch ? show(batch.id) : index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
