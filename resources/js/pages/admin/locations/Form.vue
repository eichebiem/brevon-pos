<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRightLeft, Copy, Plus } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, selectClass } from '@/lib/format';
import {
    copyAvailability,
    deactivate,
    index,
    reactivate,
    store,
    update,
} from '@/routes/admin/locations';
import {
    reassign,
    store as storeTerminal,
    update as updateTerminal,
} from '@/routes/admin/terminals';

type Location = {
    id: number;
    code: string;
    name: string;
    type: 'permanent' | 'pop_up';
    status: 'operating' | 'scheduled' | 'ended' | 'inactive';
    address_line: string | null;
    barangay: string | null;
    city_municipality: string | null;
    province: string | null;
    postal_code: string | null;
    phone: string | null;
    email: string | null;
    operating_starts_on: string | null;
    operating_ends_on: string | null;
    is_active: boolean;
    deactivated_at: string | null;
    deactivation_reason: string | null;
};

type Terminal = {
    id: number;
    terminal_code: string;
    name: string;
    is_active: boolean;
};

const props = defineProps<{
    site: Location | null;
    terminals: Terminal[];
    destinations: { id: number; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: index() }] },
});

const isPopUp = computed(() => props.site?.type !== 'permanent');
const title = computed(() =>
    props.site ? props.site.name : 'New pop-up stand',
);

const form = useForm({
    code: '',
    name: props.site?.name ?? '',
    address_line: props.site?.address_line ?? '',
    barangay: props.site?.barangay ?? '',
    city_municipality: props.site?.city_municipality ?? '',
    province: props.site?.province ?? '',
    postal_code: props.site?.postal_code ?? '',
    phone: props.site?.phone ?? '',
    email: props.site?.email ?? '',
    operating_starts_on: props.site?.operating_starts_on ?? '',
    operating_ends_on: props.site?.operating_ends_on ?? '',
    copy_availability: true,
});

function submit(): void {
    if (props.site) {
        form.transform(
            ({ code: _code, copy_availability: _copy, ...rest }) => rest,
        ).put(update(props.site.id).url, { preserveScroll: true });
    } else {
        form.post(store().url);
    }
}

const statusLabel = {
    operating: ['Operating', 'success'],
    scheduled: ['Scheduled', 'info'],
    ended: ['Period ended', 'warning'],
    inactive: ['Inactive', 'neutral'],
} as const;

// Deactivate / reactivate
const deactivateOpen = ref(false);
const deactivateForm = useForm({ reason: '' });

function submitDeactivate(): void {
    if (!props.site) {
        return;
    }

    deactivateForm.post(deactivate(props.site.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deactivateOpen.value = false;
            deactivateForm.reset();
        },
    });
}

const reactivateOpen = ref(false);
const reactivating = ref(false);

function confirmReactivate(): void {
    if (!props.site) {
        return;
    }

    router.post(
        reactivate(props.site.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (reactivating.value = true),
            onFinish: () => {
                reactivating.value = false;
                reactivateOpen.value = false;
            },
        },
    );
}

function copyFromMain(): void {
    if (props.site) {
        router.post(
            copyAvailability(props.site.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

// Terminals
const terminalForm = useForm({ terminal_code: '', name: '' });

function addTerminal(): void {
    if (!props.site) {
        return;
    }

    terminalForm.post(storeTerminal(props.site.id).url, {
        preserveScroll: true,
        onSuccess: () => terminalForm.reset(),
    });
}

const terminalEdits = reactive<
    Record<number, { name: string; is_active: boolean; move_to: string }>
>({});

// Mirror the server's terminal list into editable rows (also after adding one).
watch(
    () => props.terminals,
    (terminals) => {
        for (const t of terminals) {
            terminalEdits[t.id] = {
                name: t.name,
                is_active: t.is_active,
                move_to: '',
            };
        }
    },
    { immediate: true },
);
const terminalErrors = ref<Record<number, string>>({});

function saveTerminal(terminal: Terminal): void {
    const edit = terminalEdits[terminal.id];
    router.put(
        updateTerminal(terminal.id).url,
        { name: edit.name, is_active: edit.is_active },
        {
            preserveScroll: true,
            onError: (errors) =>
                (terminalErrors.value[terminal.id] =
                    Object.values(errors)[0] ?? ''),
            onSuccess: () => delete terminalErrors.value[terminal.id],
        },
    );
}

const moving = ref<Terminal | null>(null);
const moveOpen = ref(false);
const moveProcessing = ref(false);
const moveDestination = computed(
    () =>
        props.destinations.find(
            (d) =>
                moving.value &&
                String(d.id) === terminalEdits[moving.value.id]?.move_to,
        )?.label ?? '',
);

function askMove(terminal: Terminal): void {
    moving.value = terminal;
    moveOpen.value = true;
}

function confirmMove(): void {
    if (!moving.value) {
        return;
    }

    const terminal = moving.value;
    router.post(
        reassign(terminal.id).url,
        { branch_id: Number(terminalEdits[terminal.id].move_to) },
        {
            preserveScroll: true,
            onStart: () => (moveProcessing.value = true),
            onError: (errors) =>
                (terminalErrors.value[terminal.id] =
                    Object.values(errors)[0] ?? ''),
            onFinish: () => {
                moveProcessing.value = false;
                moveOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="font-display text-3xl text-brevon-navy dark:text-brevon-cream"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="site"
                    class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                >
                    <span class="font-medium text-foreground">{{
                        site.code
                    }}</span>
                    ·
                    {{
                        site.type === 'permanent'
                            ? 'Main branch'
                            : 'Pop-up stand'
                    }}
                    <StatusBadge :tone="statusLabel[site.status][1]">{{
                        statusLabel[site.status][0]
                    }}</StatusBadge>
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    A temporary selling site. Doughnuts are delivered from the
                    main branch; pop-ups only sell finished-stock items.
                </p>
            </div>
            <div v-if="site && isPopUp" class="flex gap-2">
                <Button
                    v-if="site.is_active"
                    variant="outline"
                    @click="deactivateOpen = true"
                    >Deactivate</Button
                >
                <Button v-else @click="reactivateOpen = true"
                    >Reactivate</Button
                >
            </div>
        </header>

        <div
            v-if="site && !site.is_active"
            class="rounded-xl border border-border bg-secondary p-4 text-sm"
            role="status"
        >
            <p class="font-semibold">
                Deactivated {{ formatDateTime(site.deactivated_at) }}
            </p>
            <p class="text-muted-foreground">
                Reason: {{ site.deactivation_reason }}. No new sales or shifts
                can start here; past records stay in reports.
            </p>
        </div>

        <form
            class="grid gap-6 rounded-xl border border-border bg-card p-5"
            @submit.prevent="submit"
        >
            <section
                class="grid gap-4 md:grid-cols-2"
                aria-labelledby="details"
            >
                <h2 id="details" class="font-semibold md:col-span-2">
                    Details
                </h2>
                <div v-if="!site" class="grid gap-2">
                    <Label for="code">Location code</Label>
                    <Input
                        id="code"
                        v-model="form.code"
                        required
                        maxlength="12"
                        placeholder="POP-SMR1"
                        class="uppercase"
                        aria-describedby="code-help"
                    />
                    <p id="code-help" class="text-xs text-muted-foreground">
                        3–12 capital letters, numbers or hyphens. It prefixes
                        receipt numbers and cannot be changed later.
                    </p>
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        maxlength="150"
                        placeholder="Brevon Pop-up — SM City Rosales"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="address_line">Street address / stall</Label>
                    <Input id="address_line" v-model="form.address_line" />
                    <InputError :message="form.errors.address_line" />
                </div>
                <div class="grid gap-2">
                    <Label for="barangay">Barangay</Label>
                    <Input id="barangay" v-model="form.barangay" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City / municipality</Label>
                    <Input id="city" v-model="form.city_municipality" />
                </div>
                <div class="grid gap-2">
                    <Label for="province">Province</Label>
                    <Input id="province" v-model="form.province" />
                </div>
                <div class="grid gap-2">
                    <Label for="postal_code">Postal code</Label>
                    <Input id="postal_code" v-model="form.postal_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" v-model="form.phone" type="tel" />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" v-model="form.email" type="email" />
                    <InputError :message="form.errors.email" />
                </div>
            </section>

            <section
                v-if="isPopUp"
                class="grid gap-4 border-t border-border pt-5 md:grid-cols-2"
                aria-labelledby="period"
            >
                <div class="md:col-span-2">
                    <h2 id="period" class="font-semibold">Operating period</h2>
                    <p class="text-xs text-muted-foreground">
                        Optional. When set, sales and shifts are only allowed on
                        these days (inclusive). Leave blank to control the stand
                        with Deactivate / Reactivate only.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="starts">First day</Label>
                    <Input
                        id="starts"
                        v-model="form.operating_starts_on"
                        type="date"
                    />
                    <InputError :message="form.errors.operating_starts_on" />
                </div>
                <div class="grid gap-2">
                    <Label for="ends">Last day</Label>
                    <Input
                        id="ends"
                        v-model="form.operating_ends_on"
                        type="date"
                    />
                    <InputError :message="form.errors.operating_ends_on" />
                </div>
            </section>

            <label
                v-if="!site"
                class="flex items-start gap-3 border-t border-border pt-5 text-sm"
            >
                <input
                    v-model="form.copy_availability"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-brevon-navy"
                />
                <span>
                    <span class="font-medium"
                        >Sell the same doughnuts as the main branch</span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >Copies the main branch's available finished-stock
                        products. You can adjust each product later.</span
                    >
                </span>
            </label>

            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ site ? 'Save location' : 'Create pop-up' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Back</Link>
                </Button>
            </div>
        </form>

        <section
            v-if="site"
            class="grid gap-4 rounded-xl border border-border bg-card p-5"
            aria-labelledby="terminals-heading"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="terminals-heading" class="font-semibold">
                        POS terminals
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Cashiers choose a terminal when they open a shift; the
                        sale is recorded at that terminal's site.
                    </p>
                </div>
                <Button
                    v-if="isPopUp"
                    variant="outline"
                    size="sm"
                    @click="copyFromMain"
                    ><Copy aria-hidden="true" /> Copy main branch
                    doughnuts</Button
                >
            </div>

            <p
                v-if="terminals.length === 0"
                class="rounded-lg bg-secondary p-4 text-sm text-secondary-foreground"
            >
                No terminals yet. Add one so cashiers can sell here.
            </p>

            <ul class="grid gap-3">
                <li
                    v-for="terminal in terminals"
                    :key="terminal.id"
                    class="flex flex-wrap items-end gap-x-6 gap-y-3 rounded-lg border border-border p-3"
                >
                    <p class="w-full font-semibold">
                        {{ terminal.terminal_code }}
                    </p>
                    <div class="grid gap-1">
                        <Label :for="`terminal-name-${terminal.id}`"
                            >Name</Label
                        >
                        <div class="flex flex-wrap items-center gap-3">
                            <Input
                                :id="`terminal-name-${terminal.id}`"
                                v-model="terminalEdits[terminal.id].name"
                                class="w-56"
                            />
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="
                                        terminalEdits[terminal.id].is_active
                                    "
                                    type="checkbox"
                                    class="size-4 accent-brevon-navy"
                                />
                                Active
                            </label>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="saveTerminal(terminal)"
                                >Save<span class="sr-only">
                                    {{ terminal.terminal_code }}</span
                                ></Button
                            >
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`terminal-move-${terminal.id}`"
                            >Move to</Label
                        >
                        <select
                            :id="`terminal-move-${terminal.id}`"
                            v-model="terminalEdits[terminal.id].move_to"
                            :class="selectClass"
                        >
                            <option value="">Choose a location…</option>
                            <option
                                v-for="d in destinations"
                                :key="d.id"
                                :value="String(d.id)"
                            >
                                {{ d.label }}
                            </option>
                        </select>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!terminalEdits[terminal.id].move_to"
                        @click="askMove(terminal)"
                        ><ArrowRightLeft aria-hidden="true" /> Move</Button
                    >
                    <p
                        v-if="terminalErrors[terminal.id]"
                        class="w-full text-sm text-red-700"
                        role="alert"
                    >
                        {{ terminalErrors[terminal.id] }}
                    </p>
                </li>
            </ul>

            <form
                class="flex flex-wrap items-end gap-3 border-t border-border pt-4"
                @submit.prevent="addTerminal"
            >
                <div class="grid gap-1">
                    <Label for="new-terminal-code">Terminal code</Label>
                    <Input
                        id="new-terminal-code"
                        v-model="terminalForm.terminal_code"
                        placeholder="POS-2"
                        class="w-32 uppercase"
                        required
                    />
                    <InputError :message="terminalForm.errors.terminal_code" />
                </div>
                <div class="grid gap-1">
                    <Label for="new-terminal-name">Name</Label>
                    <Input
                        id="new-terminal-name"
                        v-model="terminalForm.name"
                        placeholder="Front counter"
                        required
                    />
                    <InputError :message="terminalForm.errors.name" />
                </div>
                <Button type="submit" :disabled="terminalForm.processing"
                    ><Plus aria-hidden="true" /> Add terminal</Button
                >
            </form>
        </section>

        <Dialog v-model:open="deactivateOpen">
            <DialogContent>
                <form class="grid gap-4" @submit.prevent="submitDeactivate">
                    <DialogHeader>
                        <DialogTitle>Deactivate {{ site?.code }}?</DialogTitle>
                        <DialogDescription>
                            No new sales or shifts can start here. Its sales,
                            stock and shift history stay in reports. You can
                            reactivate it later.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="reason">Reason</Label>
                        <textarea
                            id="reason"
                            v-model="deactivateForm.reason"
                            required
                            minlength="5"
                            maxlength="500"
                            rows="3"
                            class="rounded-md border border-input bg-card px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                            placeholder="e.g. Mall event ended"
                        />
                        <InputError :message="deactivateForm.errors.reason" />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="deactivateForm.processing"
                        >
                            <Spinner v-if="deactivateForm.processing" />
                            Deactivate
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="reactivateOpen"
            :title="`Reactivate ${site?.code}?`"
            description="Cashiers assigned here can open shifts and sell again (within its operating dates, if set)."
            confirm-label="Reactivate"
            :processing="reactivating"
            @confirm="confirmReactivate"
        />

        <ConfirmDialog
            v-model:open="moveOpen"
            :title="`Move ${moving?.terminal_code}?`"
            :description="`From now on, sales on this terminal are recorded at ${moveDestination}. This is recorded in the audit log.`"
            confirm-label="Move terminal"
            :processing="moveProcessing"
            @confirm="confirmMove"
        />
    </div>
</template>
