<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChefHat,
    Coins,
    BadgeCheck,
    Croissant,
    FolderTree,
    History,
    LayoutGrid,
    ListPlus,
    MapPin,
    Package,
    ScrollText,
    Settings2,
    ShieldCheck,
    ShoppingCart,
    Timer,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import LocationSwitcher from '@/components/LocationSwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard, landing } from '@/routes';
import { index as auditIndex } from '@/routes/admin/audit';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { movements, stock } from '@/routes/admin/inventory';
import { index as locationsIndex } from '@/routes/admin/locations';
import { index as optionGroupsIndex } from '@/routes/admin/option-groups';
import { index as paymentMethodsIndex } from '@/routes/admin/payment-methods';
import { index as productsIndex } from '@/routes/admin/products';
import { edit as storeSettings } from '@/routes/admin/settings';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as shiftsIndex } from '@/routes/admin/shifts';
import { index as usersIndex } from '@/routes/admin/users';
import { index as pos } from '@/routes/pos';
import { payments as gcashChecks, preparation, shift } from '@/routes/staff';
import type { NavItem } from '@/types';

const { can } = usePermissions();

// Links are hidden by permission for convenience only; every route is
// enforced on the server (FR-007).
const visible = (items: NavItem[]) =>
    items.filter((item) => !item.permission || can(item.permission));

const operations = computed(() =>
    visible([
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
            permission: 'dashboard.view',
        },
        {
            title: 'POS',
            href: pos(),
            icon: ShoppingCart,
            permission: 'pos.access',
        },
        {
            title: 'My shift',
            href: shift(),
            icon: Timer,
            permission: 'shifts.open',
        },
        {
            title: 'Preparation',
            href: preparation(),
            icon: ChefHat,
            permission: 'orders.update-status',
        },
        {
            title: 'GCash to check',
            href: gcashChecks(),
            icon: BadgeCheck,
            permission: 'payments.verify-ewallet',
        },
        {
            title: 'Shifts',
            href: shiftsIndex(),
            icon: Coins,
            permission: 'shifts.review',
        },
    ]),
);

const catalog = computed(() =>
    visible([
        {
            title: 'Products',
            href: productsIndex(),
            icon: Croissant,
            permission: 'products.view',
        },
        {
            title: 'Categories',
            href: categoriesIndex(),
            icon: FolderTree,
            permission: 'products.view',
        },
        {
            title: 'Options & add-ons',
            href: optionGroupsIndex(),
            icon: ListPlus,
            permission: 'products.view',
        },
    ]),
);

const inventory = computed(() =>
    visible([
        {
            title: 'Stock on hand',
            href: stock(),
            icon: Package,
            permission: 'inventory.view',
        },
        {
            title: 'Stock movements',
            href: movements(),
            icon: History,
            permission: 'inventory.view',
        },
    ]),
);

const administration = computed(() =>
    visible([
        {
            title: 'Locations',
            href: locationsIndex(),
            icon: MapPin,
            permission: 'locations.manage',
        },
        {
            title: 'Store settings',
            href: storeSettings(),
            icon: Settings2,
            permission: 'settings.manage',
        },
        {
            title: 'Payment methods',
            href: paymentMethodsIndex(),
            icon: Wallet,
            permission: 'settings.manage',
        },
        {
            title: 'Users',
            href: usersIndex(),
            icon: Users,
            permission: 'users.manage',
        },
        {
            title: 'Roles',
            href: rolesIndex(),
            icon: ShieldCheck,
            permission: 'roles.manage',
        },
        {
            title: 'Audit log',
            href: auditIndex(),
            icon: ScrollText,
            permission: 'audit.view',
        },
    ]),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="landing()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <LocationSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-if="operations.length"
                label="Operations"
                :items="operations"
            />
            <NavMain v-if="catalog.length" label="Catalog" :items="catalog" />
            <NavMain
                v-if="inventory.length"
                label="Inventory"
                :items="inventory"
            />
            <NavMain
                v-if="administration.length"
                label="Administration"
                :items="administration"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
