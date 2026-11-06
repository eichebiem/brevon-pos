<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/tax-rules';

defineProps<{
    rules: {
        id: number;
        name: string;
        code: string | null;
        rate: string;
        inclusive: boolean;
        effective_from: string;
        effective_until: string | null;
        is_active: boolean;
        in_effect: boolean;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Tax rules', href: index() }] },
});
</script>

<template>
    <Head title="Tax rules" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    Tax rules
                </h1>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    No tax is charged until a rule is switched on. Only do this
                    with the accountant's figures: receipts stay "not an
                    official receipt" either way.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New tax rule</Link
                >
            </Button>
        </header>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    Tax rules
                </caption>
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Rule
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Rate
                        </th>
                        <th scope="col" class="px-4 py-3 font-semibold">
                            Dates
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
                    <tr v-if="rules.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No tax rules. Sales carry no tax.
                        </td>
                    </tr>
                    <tr
                        v-for="rule in rules"
                        :key="rule.id"
                        class="border-t border-border"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ rule.name }}</p>
                            <p
                                v-if="rule.code"
                                class="text-xs text-muted-foreground"
                            >
                                {{ rule.code }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            {{ Number(rule.rate) }}%
                            {{
                                rule.inclusive
                                    ? 'included in prices'
                                    : 'added on top'
                            }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ formatDate(rule.effective_from) }} –
                            {{
                                rule.effective_until
                                    ? formatDate(rule.effective_until)
                                    : 'no end'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="
                                    rule.in_effect
                                        ? 'success'
                                        : rule.is_active
                                          ? 'warning'
                                          : 'neutral'
                                "
                                >{{
                                    rule.in_effect
                                        ? 'Charged now'
                                        : rule.is_active
                                          ? 'On, outside its dates'
                                          : 'Off'
                                }}</StatusBadge
                            >
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link
                                    :href="edit(rule.id)"
                                    :aria-label="`Edit ${rule.name}`"
                                    ><Pencil aria-hidden="true" /> Edit</Link
                                >
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
