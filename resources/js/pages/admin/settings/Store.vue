<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/admin/settings';

const props = defineProps<{
    settings: {
        name: string;
        logo_url: string | null;
        receipt_footer: string | null;
        cash_approval_over: string;
        shift_review_variance_over: string;
        currency: string;
        timezone: string;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Store settings', href: edit() }] },
});

const form = useForm<{
    name: string;
    receipt_footer: string;
    logo: File | null;
    remove_logo: boolean;
    cash_approval_over: string;
    shift_review_variance_over: string;
}>({
    name: props.settings.name,
    receipt_footer: props.settings.receipt_footer ?? '',
    cash_approval_over: props.settings.cash_approval_over,
    shift_review_variance_over: props.settings.shift_review_variance_over,
    logo: null,
    remove_logo: false,
});

const preview = ref<string | null>(null);
const shownLogo = computed(() =>
    form.remove_logo ? null : (preview.value ?? props.settings.logo_url),
);

function pickLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    preview.value = file ? URL.createObjectURL(file) : null;
}

function submit(): void {
    form.transform((data) => ({ ...data, _method: 'put' })).post(update().url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            preview.value = null;
        },
    });
}
</script>

<template>
    <Head title="Store settings" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <header>
            <h1
                class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
            >
                Store settings
            </h1>
            <p class="text-sm text-muted-foreground">
                Shown on receipts and screens. Each location's own address is
                set under Locations.
            </p>
        </header>

        <section
            class="grid max-w-2xl gap-5 rounded-xl border border-border bg-card p-5"
        >
            <div class="grid gap-2">
                <Label for="name">Business name</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="logo">Logo</Label>
                <div class="flex flex-wrap items-center gap-4">
                    <img
                        v-if="shownLogo"
                        :src="shownLogo"
                        alt="Current logo"
                        class="size-20 rounded-full object-cover ring-1 ring-brevon-gold/60"
                    />
                    <span
                        v-else
                        class="grid size-20 place-items-center rounded-full bg-secondary text-xs text-secondary-foreground"
                        >Default</span
                    >
                    <div class="grid gap-2">
                        <input
                            id="logo"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-card file:px-3 file:py-1.5"
                            aria-describedby="logo-help"
                            @change="pickLogo"
                        />
                        <p id="logo-help" class="text-xs text-muted-foreground">
                            PNG, JPG or WebP, up to 1 MB. Square images look
                            best.
                        </p>
                        <label
                            v-if="settings.logo_url"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="form.remove_logo"
                                type="checkbox"
                                class="size-4 accent-brevon-navy"
                            />
                            Use the default logo instead
                        </label>
                    </div>
                </div>
                <InputError :message="form.errors.logo" />
            </div>

            <div class="grid gap-2">
                <Label for="footer">Receipt footer</Label>
                <textarea
                    id="footer"
                    v-model="form.receipt_footer"
                    rows="3"
                    maxlength="500"
                    class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
                <p class="text-xs text-muted-foreground">
                    Receipts always also state that they are not an official
                    receipt or invoice.
                </p>
                <InputError :message="form.errors.receipt_footer" />
            </div>

            <fieldset class="grid gap-4 border-t border-border pt-4">
                <legend class="pt-4 text-sm font-semibold">Cash drawer</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="cash_approval_over"
                            >Approve cash out over (₱)</Label
                        >
                        <Input
                            id="cash_approval_over"
                            v-model="form.cash_approval_over"
                            inputmode="decimal"
                            autocomplete="off"
                            aria-describedby="cash-approval-help"
                        />
                        <p
                            id="cash-approval-help"
                            class="text-xs text-muted-foreground"
                        >
                            Cash out or paid-outs above this, recorded by
                            someone who does not review shifts, wait for a
                            manager.
                        </p>
                        <InputError :message="form.errors.cash_approval_over" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shift_review_variance_over"
                            >Review shifts with a difference over (₱)</Label
                        >
                        <Input
                            id="shift_review_variance_over"
                            v-model="form.shift_review_variance_over"
                            inputmode="decimal"
                            autocomplete="off"
                            aria-describedby="variance-help"
                        />
                        <p
                            id="variance-help"
                            class="text-xs text-muted-foreground"
                        >
                            Closed shifts whose cash is short or over by more
                            than this are listed as needing review. 0.00 flags
                            any difference.
                        </p>
                        <InputError
                            :message="form.errors.shift_review_variance_over"
                        />
                    </div>
                </div>
            </fieldset>

            <dl
                class="grid gap-3 border-t border-border pt-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="font-medium">Currency</dt>
                    <dd class="text-muted-foreground">
                        {{ settings.currency }} (Philippine peso)
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">Time zone</dt>
                    <dd class="text-muted-foreground">
                        {{ settings.timezone }}
                    </dd>
                </div>
                <p class="text-xs text-muted-foreground sm:col-span-2">
                    Currency and time zone are fixed for this version because
                    every amount and business date depends on them.
                </p>
            </dl>

            <div>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" /> Save settings
                </Button>
            </div>
        </section>
    </form>
</template>
