<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { BUSINESS_TIMEZONE, selectClass } from '@/lib/format';
import type { LocationOption, Option, ReportFilters } from '@/types/reports';

const props = defineProps<{
    url: string;
    filters: ReportFilters;
    locations: LocationOption[];
    cashiers?: Option[];
    categories?: Option[];
    paymentMethods?: Option[];
}>();

const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    location: props.filters.location,
    cashier: props.filters.cashier ? String(props.filters.cashier) : '',
    category: props.filters.category ? String(props.filters.category) : '',
    payment_method: props.filters.payment_method
        ? String(props.filters.payment_method)
        : '',
    group: props.filters.group,
});

/** Today's date in Manila as YYYY-MM-DD (date picking only, no amounts). */
function manilaToday(): Date {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: BUSINESS_TIMEZONE,
    }).format(new Date());

    return new Date(`${parts}T00:00:00Z`);
}
const iso = (d: Date): string => d.toISOString().slice(0, 10);

function preset(
    kind: 'today' | 'yesterday' | '7days' | 'month' | 'lastMonth',
): void {
    const today = manilaToday();
    const day = 86_400_000;
    if (kind === 'today') {
        form.from = form.to = iso(today);
    } else if (kind === 'yesterday') {
        form.from = form.to = iso(new Date(today.getTime() - day));
    } else if (kind === '7days') {
        form.from = iso(new Date(today.getTime() - 6 * day));
        form.to = iso(today);
    } else if (kind === 'month') {
        form.from = iso(
            new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 1)),
        );
        form.to = iso(today);
    } else {
        form.from = iso(
            new Date(
                Date.UTC(today.getUTCFullYear(), today.getUTCMonth() - 1, 1),
            ),
        );
        form.to = iso(
            new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 0)),
        );
    }
    apply();
}

function apply(): void {
    router.get(
        props.url,
        Object.fromEntries(
            Object.entries(form).filter(([, v]) => v !== '' && v !== null),
        ),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <form
        class="grid gap-3 rounded-xl border border-border bg-card p-4 print:hidden"
        role="search"
        aria-label="Report filters"
        @submit.prevent="apply"
    >
        <div class="flex flex-wrap gap-2" aria-label="Quick dates">
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="preset('today')"
                >Today</Button
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="preset('yesterday')"
                >Yesterday</Button
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="preset('7days')"
                >Last 7 days</Button
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="preset('month')"
                >This month</Button
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="preset('lastMonth')"
                >Last month</Button
            >
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1">
                <label for="report-from" class="text-xs font-medium"
                    >From</label
                >
                <Input
                    id="report-from"
                    v-model="form.from"
                    type="date"
                    required
                />
            </div>
            <div class="grid gap-1">
                <label for="report-to" class="text-xs font-medium">To</label>
                <Input id="report-to" v-model="form.to" type="date" required />
            </div>
            <div class="grid gap-1">
                <label for="report-location" class="text-xs font-medium"
                    >Location</label
                >
                <select
                    id="report-location"
                    v-model="form.location"
                    :class="selectClass"
                >
                    <option value="all">All my locations</option>
                    <option
                        v-for="l in locations"
                        :key="l.id"
                        :value="String(l.id)"
                    >
                        {{ l.label }}
                    </option>
                </select>
            </div>
            <div v-if="cashiers" class="grid gap-1">
                <label for="report-cashier" class="text-xs font-medium"
                    >Cashier</label
                >
                <select
                    id="report-cashier"
                    v-model="form.cashier"
                    :class="selectClass"
                >
                    <option value="">All cashiers</option>
                    <option
                        v-for="c in cashiers"
                        :key="c.id"
                        :value="String(c.id)"
                    >
                        {{ c.name }}
                    </option>
                </select>
            </div>
            <div v-if="categories" class="grid gap-1">
                <label for="report-category" class="text-xs font-medium"
                    >Category</label
                >
                <select
                    id="report-category"
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
            <div v-if="paymentMethods" class="grid gap-1">
                <label for="report-method" class="text-xs font-medium"
                    >Paid by</label
                >
                <select
                    id="report-method"
                    v-model="form.payment_method"
                    :class="selectClass"
                >
                    <option value="">Any method</option>
                    <option
                        v-for="m in paymentMethods"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
            </div>
            <Button type="submit" class="min-h-10">Apply</Button>
        </div>
    </form>
</template>
