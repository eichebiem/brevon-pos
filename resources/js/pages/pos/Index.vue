<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    Minus,
    Plus,
    Search,
    Smartphone,
    Timer,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ItemDialog from '@/components/pos/ItemDialog.vue';
import PaymentDialog from '@/components/pos/PaymentDialog.vue';
import ProductTile from '@/components/pos/ProductTile.vue';
import SaleCompleteDialog from '@/components/pos/SaleCompleteDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, selectClass } from '@/lib/format';
import { postJson, ValidationFailed } from '@/lib/http';
import { checkout, quote as quoteRoute } from '@/routes/pos';
import { shift as shiftPage } from '@/routes/staff';
import type {
    CartLine,
    LastOrder,
    PosCatalog,
    PosOrderType,
    PosPaymentMethod,
    PosProduct,
    Quote,
} from '@/types/pos';

const props = defineProps<{
    canOpenShift: boolean;
    canSell: boolean;
    canVerifyEwallet: boolean;
    catalog: PosCatalog | null;
    paymentMethods: PosPaymentMethod[];
    orderTypes: PosOrderType[];
}>();

const page = usePage();
const activeShift = computed(() => page.props.activeShift);

// ---- Catalog browsing (FR-024) -------------------------------------------
const tab = ref<number | 'all'>('all');
const search = ref('');
const searchInput = ref<InstanceType<typeof Input> | null>(null);

const products = computed(() => props.catalog?.products ?? []);
const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return products.value.filter((p) =>
        term !== ''
            ? `${p.name} ${p.sku}`.toLowerCase().includes(term)
            : tab.value === 'all' || p.tab_id === tab.value,
    );
});
// Sub-category headings within a tab (e.g. Regular / Premium doughnuts).
const sections = computed(() => {
    const groups = new Map<string, PosProduct[]>();
    for (const p of visible.value) {
        const key = p.group ?? '';
        groups.set(key, [...(groups.get(key) ?? []), p]);
    }

    return [...groups.entries()].map(([name, items]) => ({ name, items }));
});
const byId = computed(
    () => new Map(products.value.map((p) => [p.id, p] as const)),
);

// ---- Cart (FR-025): ids and choices only; prices come from the server ----
const storageKey = computed(() =>
    activeShift.value ? `brevon.pos.cart.${activeShift.value.number}` : null,
);
const cart = ref<CartLine[]>([]);
const orderType = ref(props.orderTypes[0]?.value ?? 'walk_in');
const orderNotes = ref('');
const pickupAt = ref('');

function restoreCart(): void {
    if (!storageKey.value) {
        return;
    }
    try {
        const saved = JSON.parse(
            sessionStorage.getItem(storageKey.value) ?? 'null',
        ) as { cart: CartLine[]; orderType: string; orderNotes: string } | null;
        if (saved) {
            // Drop lines whose product is no longer sold here.
            cart.value = saved.cart.filter((l) => byId.value.has(l.product_id));
            orderType.value = saved.orderType;
            orderNotes.value = saved.orderNotes;
        }
    } catch {
        sessionStorage.removeItem(storageKey.value);
    }
}

watch(
    [cart, orderType, orderNotes],
    () => {
        if (storageKey.value) {
            sessionStorage.setItem(
                storageKey.value,
                JSON.stringify({
                    cart: cart.value,
                    orderType: orderType.value,
                    orderNotes: orderNotes.value,
                }),
            );
        }
    },
    { deep: true },
);

const inCart = computed(() => {
    const counts = new Map<number, number>();
    for (const l of cart.value) {
        counts.set(l.product_id, (counts.get(l.product_id) ?? 0) + l.quantity);
    }

    return counts;
});

const pickerOpen = ref(false);
const picking = ref<PosProduct | null>(null);

function pick(product: PosProduct): void {
    if (product.variants.length > 0 || product.option_groups.length > 0) {
        picking.value = product;
        pickerOpen.value = true;

        return;
    }
    addLine(product.id, {
        variant_id: null,
        option_ids: [],
        quantity: 1,
        notes: '',
    });
}

function addLine(
    productId: number,
    choice: {
        variant_id: number | null;
        option_ids: number[];
        quantity: number;
        notes: string;
    },
): void {
    const options = [...choice.option_ids].sort((a, b) => a - b);
    const key = `${productId}:${choice.variant_id ?? ''}:${options.join(',')}:${choice.notes}`;
    const existing = cart.value.find((l) => l.key === key);
    if (existing) {
        existing.quantity = Math.min(999, existing.quantity + choice.quantity);

        return;
    }
    cart.value.push({
        key,
        product_id: productId,
        variant_id: choice.variant_id,
        option_ids: options,
        quantity: choice.quantity,
        notes: choice.notes,
    });
}

function changeQuantity(line: CartLine, by: number): void {
    line.quantity = Math.max(1, Math.min(999, line.quantity + by));
}

function removeLine(index: number): void {
    cart.value.splice(index, 1);
}

function clearSale(): void {
    cart.value = [];
    orderNotes.value = '';
    pickupAt.value = '';
    orderType.value = props.orderTypes[0]?.value ?? 'walk_in';
    lineErrors.value = {};
    banner.value = null;
}

// ---- Server quote (FR-026): totals are never calculated here (D-034) ------
const quote = ref<Quote | null>(null);
const quoting = ref(false);
const lineErrors = ref<Record<number, string>>({});
const banner = ref<string | null>(null);
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteAbort: AbortController | null = null;

function itemsPayload() {
    return cart.value.map((l) => ({
        product_id: l.product_id,
        variant_id: l.variant_id,
        quantity: l.quantity,
        option_ids: l.option_ids,
        notes: l.notes || null,
    }));
}

function applyErrors(errors: Record<string, string | string[]>): void {
    const lines: Record<number, string> = {};
    let general: string | null = null;
    for (const [key, value] of Object.entries(errors)) {
        const message = Array.isArray(value) ? value[0] : value;
        const match = key.match(/^items\.(\d+)/);
        if (match) {
            lines[Number(match[1])] = message;
        } else if (
            key === 'items' ||
            key === 'shift' ||
            key === 'expected_total'
        ) {
            general = message;
        }
    }
    lineErrors.value = lines;
    banner.value = general;
}

async function refreshQuote(tendered: string | null = null): Promise<void> {
    quoteAbort?.abort();
    if (cart.value.length === 0) {
        quote.value = null;
        lineErrors.value = {};
        banner.value = null;

        return;
    }
    quoteAbort = new AbortController();
    quoting.value = true;
    try {
        quote.value = await postJson<Quote>(
            quoteRoute().url,
            {
                items: itemsPayload(),
                payment:
                    tendered !== null && tendered !== ''
                        ? { amount_tendered: tendered }
                        : null,
            },
            quoteAbort.signal,
        );
        lineErrors.value = {};
        banner.value = null;
    } catch (error) {
        if (error instanceof ValidationFailed) {
            quote.value = null;
            applyErrors(error.errors);
        } else if (!(error instanceof DOMException)) {
            banner.value =
                'Could not get the total. Check the connection and try again.';
        }
    } finally {
        quoting.value = false;
    }
}

watch(
    cart,
    () => {
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(() => void refreshQuote(), 200);
    },
    { deep: true },
);

const total = computed(() => quote.value?.grand_total ?? null);
const itemCount = computed(() =>
    cart.value.reduce((n, l) => n + l.quantity, 0),
);
const selectedType = computed(() =>
    props.orderTypes.find((t) => t.value === orderType.value),
);

// ---- Payment and checkout (FR-031–FR-038) -------------------------------
const payOpen = ref(false);
const payMethod = ref<PosPaymentMethod | null>(null);
const tendered = ref('');
const change = ref<string | null>(null);
const processing = ref(false);
const paymentErrors = ref<Record<string, string | undefined>>({});

// One key per distinct sale attempt: a retry of the same request reuses it
// (the server returns the same order), any change gets a fresh key (D-105).
let idempotencyKey = crypto.randomUUID();
let lastAttempt = '';

let changeTimer: ReturnType<typeof setTimeout> | undefined;
watch(tendered, (value) => {
    change.value = null;
    clearTimeout(changeTimer);
    if (value.trim() !== '' && /^\d+(\.\d{1,2})?$/.test(value.trim())) {
        changeTimer = setTimeout(async () => {
            await refreshQuote(value.trim());
            change.value = quote.value?.change ?? null;
        }, 200);
    }
});

const canCheckout = computed(
    () =>
        props.canSell &&
        cart.value.length > 0 &&
        quote.value !== null &&
        !quoting.value &&
        Object.keys(lineErrors.value).length === 0 &&
        (!selectedType.value?.needs_pickup || pickupAt.value !== ''),
);

function openPayment(method: PosPaymentMethod): void {
    if (!canCheckout.value) {
        return;
    }
    payMethod.value = method;
    paymentErrors.value = {};
    change.value = null;
    payOpen.value = true;
}

const lastOrder = ref<LastOrder | null>(null);
const completeOpen = ref(false);

function submit(payment: { reference: string; verified: boolean }): void {
    if (!payMethod.value || processing.value || !quote.value) {
        return;
    }
    const isCash = payMethod.value.type === 'cash';
    const body = {
        order_type: orderType.value,
        notes: orderNotes.value.trim() || null,
        pickup_at: selectedType.value?.needs_pickup ? pickupAt.value : null,
        items: itemsPayload(),
        expected_total: quote.value.grand_total,
        payment: {
            method: payMethod.value.code,
            amount_tendered: isCash ? tendered.value.trim() : null,
            reference: isCash ? null : payment.reference,
            verified: isCash ? false : payment.verified,
        },
    };
    const attempt = JSON.stringify(body);
    if (attempt !== lastAttempt) {
        idempotencyKey = crypto.randomUUID();
        lastAttempt = attempt;
    }

    processing.value = true;
    router.post(
        checkout().url,
        { ...body, idempotency_key: idempotencyKey },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                payOpen.value = false;
                clearSale();
                lastAttempt = '';
            },
            onError: (errors) => {
                paymentErrors.value = errors;
                applyErrors(errors);
                if (
                    Object.keys(errors).some((k) => !k.startsWith('payment.'))
                ) {
                    payOpen.value = false;
                    void refreshQuote();
                }
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

// The server flashes the completed order (FR-033).
let stopFlash: (() => void) | undefined;
onMounted(() => {
    restoreCart();
    stopFlash = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            | { lastOrder?: LastOrder }
            | undefined;
        if (flash?.lastOrder) {
            lastOrder.value = flash.lastOrder;
            completeOpen.value = true;
        }
    });
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    stopFlash?.();
    window.removeEventListener('keydown', onKey);
});

// ---- Keyboard shortcuts --------------------------------------------------
const cash = computed(() =>
    props.paymentMethods.find((m) => m.type === 'cash'),
);
const ewallet = computed(() =>
    props.paymentMethods.find((m) => m.type !== 'cash'),
);

function onKey(event: KeyboardEvent): void {
    const typing = (event.target as HTMLElement | null)?.closest(
        'input, textarea, select, [contenteditable]',
    );
    if (payOpen.value || pickerOpen.value || completeOpen.value) {
        return;
    }
    if (event.key === '/' && !typing) {
        event.preventDefault();
        (searchInput.value?.$el as HTMLInputElement | undefined)?.focus();
    } else if (event.key === 'F8' && cash.value) {
        event.preventDefault();
        openPayment(cash.value);
    } else if (event.key === 'F9' && ewallet.value) {
        event.preventDefault();
        openPayment(ewallet.value);
    }
}

function lineFor(index: number) {
    return quote.value?.lines[index] ?? null;
}

function lineLabel(line: CartLine) {
    const product = byId.value.get(line.product_id);
    const variant = product?.variants.find((v) => v.id === line.variant_id);
    const options = product?.option_groups
        .flatMap((g) => g.options)
        .filter((o) => line.option_ids.includes(o.id))
        .map((o) => o.name);

    return {
        name: product?.name ?? 'Item no longer sold here',
        detail: [variant?.name, ...(options ?? [])].filter(Boolean).join(' · '),
    };
}

watch(
    () => props.catalog,
    () => {
        if (
            tab.value !== 'all' &&
            !props.catalog?.categories.some((c) => c.id === tab.value)
        ) {
            tab.value = 'all';
        }
    },
);
</script>

<template>
    <Head title="POS" />

    <!-- No shift: offer to open one (BR-017, D-103). -->
    <div
        v-if="!activeShift || !catalog"
        class="flex flex-1 flex-col items-center justify-center gap-6 p-6"
    >
        <section
            class="flex w-full max-w-xl flex-col items-center gap-3 rounded-2xl border border-border bg-card p-8 text-center"
            aria-labelledby="no-shift-heading"
        >
            <h2
                id="no-shift-heading"
                class="font-display text-2xl text-brevon-navy dark:text-brevon-cream"
            >
                Open a shift to start selling
            </h2>
            <p class="text-sm text-muted-foreground">
                Choose your terminal and count the opening cash. Sales are
                recorded at the terminal's location.
            </p>
            <Button v-if="canOpenShift" size="lg" as-child>
                <Link :href="shiftPage()"
                    ><Timer aria-hidden="true" /> Open shift</Link
                >
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                Your role cannot open shifts. Ask a manager.
            </p>
        </section>
    </div>

    <div v-else class="flex min-h-0 flex-1 flex-col md:flex-row">
        <!-- Products -->
        <section
            class="flex min-h-0 min-w-0 flex-1 flex-col"
            aria-label="Products"
        >
            <div class="flex flex-col gap-3 border-b border-border p-3 md:p-4">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        placeholder="Search products ( / )"
                        aria-label="Search products"
                        class="h-11 pl-9"
                        data-test="pos-search"
                        @keydown.escape="search = ''"
                    />
                </div>
                <div
                    class="flex gap-2 overflow-x-auto pb-1"
                    role="tablist"
                    aria-label="Categories"
                >
                    <button
                        v-for="item in [
                            { id: 'all' as const, name: 'All' },
                            ...catalog.categories,
                        ]"
                        :key="item.id"
                        type="button"
                        role="tab"
                        :aria-selected="tab === item.id && search === ''"
                        :class="[
                            'min-h-11 shrink-0 rounded-full border px-4 text-sm font-medium whitespace-nowrap',
                            tab === item.id && search === ''
                                ? 'border-brevon-navy bg-brevon-navy text-brevon-cream'
                                : 'border-border bg-card hover:border-brevon-gold',
                        ]"
                        @click="
                            tab = item.id;
                            search = '';
                        "
                    >
                        {{ item.name }}
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3 md:p-4">
                <p
                    v-if="visible.length === 0"
                    class="py-16 text-center text-sm text-muted-foreground"
                >
                    {{
                        products.length === 0
                            ? 'Nothing is available to sell here yet. Ask a manager to set products and prices for this location.'
                            : 'No products match your search.'
                    }}
                </p>
                <div
                    v-for="section in sections"
                    :key="section.name"
                    class="mb-5"
                >
                    <h3
                        v-if="section.name"
                        class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ section.name }}
                    </h3>
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5"
                    >
                        <ProductTile
                            v-for="product in section.items"
                            :key="product.id"
                            :product="product"
                            :in-cart="inCart.get(product.id) ?? 0"
                            @pick="pick"
                        />
                    </div>
                </div>
            </div>
        </section>

        <!-- Current order -->
        <aside
            class="flex max-h-[55svh] w-full shrink-0 flex-col border-t border-border bg-card md:max-h-none md:w-[22rem] md:border-t-0 md:border-l lg:w-[26rem]"
            aria-label="Current order"
        >
            <div
                class="flex items-center justify-between gap-2 border-b border-border px-4 py-3"
            >
                <h2 class="font-display text-xl">
                    Current order
                    <span
                        v-if="itemCount"
                        class="ml-1 text-sm font-normal text-muted-foreground"
                        >· {{ itemCount }} item{{
                            itemCount === 1 ? '' : 's'
                        }}</span
                    >
                </h2>
                <Button
                    v-if="cart.length"
                    variant="ghost"
                    size="sm"
                    class="min-h-11"
                    @click="clearSale"
                    ><X aria-hidden="true" /> Clear</Button
                >
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <p
                    v-if="cart.length === 0"
                    class="px-4 py-12 text-center text-sm text-muted-foreground"
                >
                    Tap a product to add it.
                </p>
                <ul v-else class="divide-y divide-border" data-test="pos-cart">
                    <li
                        v-for="(line, index) in cart"
                        :key="line.key"
                        :class="[
                            'px-4 py-3',
                            lineErrors[index]
                                ? 'bg-red-50 dark:bg-red-950/30'
                                : '',
                        ]"
                    >
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">
                                    {{ lineLabel(line).name }}
                                </p>
                                <p
                                    v-if="lineLabel(line).detail"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ lineLabel(line).detail }}
                                </p>
                                <p
                                    v-if="line.notes"
                                    class="text-xs text-muted-foreground italic"
                                >
                                    “{{ line.notes }}”
                                </p>
                            </div>
                            <span
                                class="text-sm font-semibold whitespace-nowrap"
                                >{{
                                    lineFor(index)
                                        ? formatMoney(
                                              lineFor(index)!.line_total,
                                          )
                                        : '…'
                                }}</span
                            >
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                class="size-10"
                                :aria-label="`One less ${lineLabel(line).name}`"
                                :disabled="line.quantity <= 1"
                                @click="changeQuantity(line, -1)"
                                ><Minus
                            /></Button>
                            <span
                                class="w-8 text-center font-semibold tabular-nums"
                                aria-live="polite"
                                >{{ line.quantity }}</span
                            >
                            <Button
                                variant="outline"
                                size="icon"
                                class="size-10"
                                :aria-label="`One more ${lineLabel(line).name}`"
                                :disabled="line.quantity >= 999"
                                @click="changeQuantity(line, 1)"
                                ><Plus
                            /></Button>
                            <span
                                v-if="lineFor(index)"
                                class="text-xs text-muted-foreground"
                                >×
                                {{
                                    formatMoney(lineFor(index)!.unit_total)
                                }}</span
                            >
                            <Button
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-10 text-red-700"
                                :aria-label="`Remove ${lineLabel(line).name}`"
                                @click="removeLine(index)"
                                ><Trash2
                            /></Button>
                        </div>
                        <p
                            v-if="lineErrors[index]"
                            class="mt-1 text-xs font-medium text-red-700"
                            role="alert"
                        >
                            {{ lineErrors[index] }}
                        </p>
                    </li>
                </ul>
            </div>

            <div class="grid gap-3 border-t border-border p-4">
                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1">
                        <Label for="order-type" class="text-xs"
                            >Order type</Label
                        >
                        <select
                            id="order-type"
                            v-model="orderType"
                            :class="[selectClass, 'h-11']"
                        >
                            <option
                                v-for="type in orderTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                    </div>
                    <div v-if="selectedType?.needs_pickup" class="grid gap-1">
                        <Label for="pickup-at" class="text-xs">Pickup</Label>
                        <Input
                            id="pickup-at"
                            v-model="pickupAt"
                            type="datetime-local"
                            class="h-11"
                        />
                    </div>
                    <div
                        :class="[
                            'grid gap-1',
                            selectedType?.needs_pickup ? 'col-span-2' : '',
                        ]"
                    >
                        <Label for="order-notes" class="text-xs"
                            >Order note</Label
                        >
                        <Input
                            id="order-notes"
                            v-model="orderNotes"
                            maxlength="500"
                            placeholder="No names or phone numbers"
                            class="h-11"
                        />
                    </div>
                </div>
                <p
                    v-if="paymentErrors.pickup_at"
                    class="text-xs text-red-700"
                    role="alert"
                >
                    {{ paymentErrors.pickup_at }}
                </p>

                <p
                    v-if="banner"
                    class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-900"
                    role="alert"
                    data-test="pos-banner"
                >
                    {{ banner }}
                </p>

                <dl class="flex items-baseline justify-between">
                    <dt class="text-sm font-medium">Total</dt>
                    <dd
                        class="flex items-center gap-2 font-display text-3xl text-brevon-navy dark:text-brevon-gold-light"
                        data-test="pos-total"
                    >
                        <Spinner v-if="quoting" class="size-4" />
                        {{ total ? formatMoney(total) : formatMoney('0') }}
                    </dd>
                </dl>

                <div class="grid grid-cols-2 gap-2">
                    <Button
                        v-for="method in paymentMethods"
                        :key="method.code"
                        size="lg"
                        :variant="
                            method.type === 'cash' ? 'default' : 'outline'
                        "
                        class="min-h-14 text-base"
                        :disabled="!canCheckout"
                        :data-test="`pos-pay-${method.code}`"
                        @click="openPayment(method)"
                    >
                        <Banknote
                            v-if="method.type === 'cash'"
                            aria-hidden="true"
                        />
                        <Smartphone v-else aria-hidden="true" />
                        {{ method.name }}
                        <kbd
                            class="hidden rounded border border-current/30 px-1 text-[10px] opacity-70 lg:inline"
                            >{{ method.type === 'cash' ? 'F8' : 'F9' }}</kbd
                        >
                    </Button>
                </div>
                <p v-if="!canSell" class="text-xs text-muted-foreground">
                    Your role can view the POS but not take payments.
                </p>
            </div>
        </aside>
    </div>

    <ItemDialog
        v-model:open="pickerOpen"
        :product="picking"
        @add="(line) => picking && addLine(picking.id, line)"
    />
    <PaymentDialog
        v-model:open="payOpen"
        v-model:tendered="tendered"
        :method="payMethod"
        :total="total"
        :change="change"
        :can-verify="canVerifyEwallet"
        :processing="processing"
        :errors="paymentErrors"
        @confirm="submit"
    />
    <SaleCompleteDialog v-model:open="completeOpen" :order="lastOrder" />
</template>
