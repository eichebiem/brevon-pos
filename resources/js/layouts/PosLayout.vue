<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    LogOut,
    ReceiptText,
    ShoppingCart,
    Timer,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { usePermissions } from '@/composables/usePermissions';
import { landing, logout } from '@/routes';
import { index as posIndex } from '@/routes/pos';
import { index as ordersIndex } from '@/routes/pos/orders';
import { shift as shiftPage } from '@/routes/staff';

const page = usePage();
const user = computed(() => page.props.auth.user);
const activeShift = computed(() => page.props.activeShift);
const { can } = usePermissions();

const linkClass =
    'inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg whitespace-nowrap border border-brevon-gold/50 px-4 text-sm font-medium hover:bg-brevon-navy-dark';
</script>

<template>
    <!-- Full-screen cashier layout (prompt §4). Minimal navigation by design. -->
    <div class="flex h-svh flex-col bg-background text-foreground">
        <header
            class="flex h-16 shrink-0 items-center gap-4 bg-brevon-navy px-4 text-brevon-cream"
        >
            <AppLogoIcon class="size-10 ring-1 ring-brevon-gold/60" alt="" />
            <div class="min-w-0 flex-1 leading-tight">
                <p class="font-display text-xl">Brevon POS</p>
                <p
                    class="truncate text-xs text-brevon-gold-light"
                    data-test="pos-shift-context"
                >
                    <template v-if="activeShift">
                        {{ activeShift.location_code }} ·
                        {{ activeShift.location }} ·
                        {{ activeShift.terminal }} · Shift
                        {{ activeShift.number }}
                    </template>
                    <template v-else>No open shift</template>
                </p>
            </div>
            <nav class="flex shrink-0 items-center gap-2" aria-label="POS">
                <Link v-if="activeShift" :href="posIndex()" :class="linkClass">
                    <ShoppingCart class="size-4" aria-hidden="true" />
                    <span class="hidden md:inline">Sale</span>
                </Link>
                <Link
                    v-if="can('orders.view')"
                    :href="ordersIndex()"
                    :class="linkClass"
                    data-test="pos-orders-link"
                >
                    <ReceiptText class="size-4" aria-hidden="true" />
                    <span class="hidden md:inline">Orders</span>
                </Link>
                <Link
                    v-if="can('shifts.open') || can('shifts.close')"
                    :href="shiftPage()"
                    :class="linkClass"
                >
                    <Timer class="size-4" aria-hidden="true" />
                    <span class="hidden md:inline">Shift</span>
                </Link>
                <Link
                    v-if="can('dashboard.view')"
                    :href="landing()"
                    :class="linkClass"
                >
                    <LayoutDashboard class="size-4" aria-hidden="true" />
                    <span class="hidden md:inline">Back office</span>
                </Link>
                <span class="hidden px-2 text-sm whitespace-nowrap lg:inline">{{
                    user.name
                }}</span>
                <Link
                    :href="logout()"
                    as="button"
                    :class="linkClass"
                    @click="router.flushAll()"
                    data-test="pos-logout-button"
                >
                    <LogOut class="size-4" aria-hidden="true" />
                    <span class="hidden md:inline">Log out</span>
                </Link>
            </nav>
        </header>
        <main class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
