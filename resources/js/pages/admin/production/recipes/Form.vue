<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/format';
import { index, show, store } from '@/routes/admin/recipes';

type Line = {
    inventory_item_id: number;
    quantity: string;
    wastage_percent: string;
};

const props = defineProps<{
    product: {
        id: number;
        name: string;
        sku: string;
        at_sale: boolean;
        unit: string | null;
    };
    size: { id: number; name: string } | null;
    current: {
        version: number;
        makes: string;
        notes: string | null;
        lines: Line[];
    } | null;
    ingredients: {
        id: number;
        sku: string;
        name: string;
        unit: string | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Recipes', href: index() }] },
});

const form = useForm({
    size_id: props.size?.id ?? null,
    output_quantity: props.current
        ? String(Number(props.current.makes))
        : props.product.at_sale
          ? '1'
          : '',
    notes: '',
    lines: (props.current?.lines ?? []).map((l) => ({ ...l })) as Line[],
});

const byId = computed(() => new Map(props.ingredients.map((i) => [i.id, i])));
const available = computed(() =>
    props.ingredients.filter(
        (i) => !form.lines.some((l) => l.inventory_item_id === i.id),
    ),
);
const picked = ref('');

function addLine(): void {
    if (picked.value) {
        form.lines.push({
            inventory_item_id: Number(picked.value),
            quantity: '',
            wastage_percent: '',
        });
        picked.value = '';
    }
}

const errors = computed(() => form.errors as Record<string, string>);
const title = computed(
    () =>
        `${props.current ? 'Change' : 'Add'} recipe: ${props.product.name}${props.size ? ` (${props.size.name})` : ''}`,
);
</script>

<template>
    <Head :title="title" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        novalidate
        @submit.prevent="form.post(store(product.id).url)"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                {{ title }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="current"
                    >Saving creates version {{ current.version + 1 }}; version
                    {{ current.version }} stays in the history.
                </template>
                <template v-if="product.at_sale"
                    >Amounts for one serving{{
                        size ? ` of ${size.name}` : ' of every size'
                    }}; taken from stock at each sale.</template
                >
                <template v-else
                    >Amounts for one batch; taken from stock when a batch is
                    posted, in proportion to what was made.</template
                >
            </p>
        </header>

        <section
            v-if="!product.at_sale"
            class="grid max-w-3xl gap-2 rounded-xl border border-border bg-card p-5"
        >
            <Label for="output_quantity">This recipe makes</Label>
            <div class="flex items-center gap-2">
                <Input
                    id="output_quantity"
                    v-model="form.output_quantity"
                    class="w-28"
                    inputmode="numeric"
                    data-test="recipe-makes"
                />
                <span class="text-sm text-muted-foreground">{{
                    product.unit ?? 'pc'
                }}</span>
            </div>
            <InputError :message="form.errors.output_quantity" />
        </section>

        <section
            class="grid max-w-3xl gap-4 rounded-xl border border-border bg-card p-5"
            aria-labelledby="ingredients-heading"
        >
            <h2 id="ingredients-heading" class="font-semibold">Ingredients</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">Item</th>
                        <th scope="col" class="py-2 pr-3 font-medium">
                            Amount
                        </th>
                        <th scope="col" class="py-2 pr-3 font-medium">
                            Wastage %
                        </th>
                        <th scope="col">
                            <span class="sr-only">Remove</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="form.lines.length === 0">
                        <td
                            colspan="4"
                            class="py-6 text-center text-muted-foreground"
                        >
                            No ingredients yet. Pick one below.
                        </td>
                    </tr>
                    <tr
                        v-for="(line, i) in form.lines"
                        :key="line.inventory_item_id"
                        class="border-t border-border align-top"
                    >
                        <td class="py-2 pr-3">
                            <p class="font-medium">
                                {{ byId.get(line.inventory_item_id)?.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ byId.get(line.inventory_item_id)?.sku }}
                            </p>
                        </td>
                        <td class="py-2 pr-3">
                            <div class="flex items-center gap-1">
                                <Input
                                    v-model="line.quantity"
                                    class="w-24"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :aria-label="`Amount of ${byId.get(line.inventory_item_id)?.name}`"
                                    :data-test="`recipe-qty-${byId.get(line.inventory_item_id)?.sku}`"
                                />
                                <span class="text-xs text-muted-foreground">{{
                                    byId.get(line.inventory_item_id)?.unit
                                }}</span>
                            </div>
                            <InputError
                                :message="errors[`lines.${i}.quantity`]"
                            />
                        </td>
                        <td class="py-2 pr-3">
                            <Input
                                v-model="line.wastage_percent"
                                class="w-20"
                                inputmode="decimal"
                                autocomplete="off"
                                placeholder="0"
                                :aria-label="`Wastage percent of ${byId.get(line.inventory_item_id)?.name}`"
                            />
                            <InputError
                                :message="errors[`lines.${i}.wastage_percent`]"
                            />
                        </td>
                        <td class="py-2">
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                :aria-label="`Remove ${byId.get(line.inventory_item_id)?.name}`"
                                @click="form.lines.splice(i, 1)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <InputError :message="errors.lines ?? errors.product" />

            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1">
                    <Label for="add-item">Add an ingredient</Label>
                    <select
                        id="add-item"
                        v-model="picked"
                        :class="[selectClass, 'h-10 min-w-64']"
                        data-test="recipe-add-item"
                    >
                        <option value="">Choose an item</option>
                        <option
                            v-for="item in available"
                            :key="item.id"
                            :value="String(item.id)"
                        >
                            {{ item.name }} ({{ item.sku }})
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!picked"
                    data-test="recipe-add-line"
                    @click="addLine"
                    ><Plus aria-hidden="true" /> Add</Button
                >
            </div>
            <p
                v-if="ingredients.length === 0"
                class="text-sm text-muted-foreground"
            >
                Add ingredients under Stock items first.
            </p>

            <div class="grid gap-2">
                <Label for="notes">What changed (optional)</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="1000"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
            </div>
        </section>

        <div class="flex gap-3">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-recipe"
            >
                <Spinner v-if="form.processing" /> Save
                {{ current ? `version ${current.version + 1}` : 'recipe' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="current ? show(product.id) : index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
