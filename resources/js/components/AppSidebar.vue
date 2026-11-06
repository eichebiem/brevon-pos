<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ChartColumn,
    ChefHat,
    Coins,
    BadgeCheck,
    BadgePercent,
    BookOpen,
    Boxes,
    Calculator,
    CalendarClock,
    ClipboardCheck,
    CookingPot,
    Croissant,
    Factory,
    Landmark,
    FileText,
    FolderTree,
    History,
    LayoutGrid,
    ListPlus,
    MapPin,
    Package,
    PackageCheck,
    ScrollText,
    Settings2,
    ShieldCheck,
    ShoppingCart,
    Timer,
    Trash2,
    Truck,
    Undo2,
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
import { lots, movements, stock, valuation } from '@/routes/admin/inventory';
import { index as adjustmentsIndex } from '@/routes/admin/inventory/adjustments';
import { index as dispatchesIndex } from '@/routes/admin/inventory/dispatches';
import { index as itemsIndex } from '@/routes/admin/inventory/items';
import { index as locationsIndex } from '@/routes/admin/locations';
import { index as optionGroupsIndex } from '@/routes/admin/option-groups';
import { index as paymentMethodsIndex } from '@/routes/admin/payment-methods';
import { index as taxRulesIndex } from '@/routes/admin/tax-rules';
import { index as productionIndex } from '@/routes/admin/production';
import { index as productsIndex } from '@/routes/admin/products';
import { index as purchaseOrdersIndex } from '@/routes/admin/purchase-orders';
import { index as receiptsIndex } from '@/routes/admin/receipts';
import { index as recipesIndex } from '@/routes/admin/recipes';
import {
    sales as salesReport,
    show as showReport,
} from '@/routes/admin/reports';
import { edit as storeSettings } from '@/routes/admin/settings';
import { index as discountsIndex } from '@/routes/admin/discounts';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as shiftsIndex } from '@/routes/admin/shifts';
import { index as suppliersIndex } from '@/routes/admin/suppliers';
import { index as usersIndex } from '@/routes/admin/users';
import { index as pos } from '@/routes/pos';
import { payments as gcashChecks, preparation, shift } from '@/routes/staff';
import type { NavItem } from '@/types';

const { can } = usePermissions();

// Links are hidden by permission for convenience only; every route is
// enforced on the server (FR-007). "a|b" means either permission.
const visible = (items: NavItem[]) =>
    items.filter(
        (item) => !item.permission || item.permission.split('|').some(can),
    );

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
        {
            title: 'Counts & adjustments',
            href: adjustmentsIndex(),
            icon: ClipboardCheck,
            permission: 'inventory.adjust',
        },
        {
            title: 'Dispatches',
            href: dispatchesIndex(),
            icon: Truck,
            permission: 'inventory.view',
        },
        {
            title: 'Stock items',
            href: itemsIndex(),
            icon: Boxes,
            permission: 'inventory.view',
        },
        {
            title: 'Lots & expiry',
            href: lots(),
            icon: CalendarClock,
            permission: 'inventory.view',
        },
        {
            title: 'Stock value',
            href: valuation(),
            icon: Calculator,
            permission: 'reports.inventory',
        },
    ]),
);

const production = computed(() =>
    visible([
        {
            title: 'Production',
            href: productionIndex(),
            icon: CookingPot,
            permission: 'production.create|production.post',
        },
        {
            title: 'Recipes',
            href: recipesIndex(),
            icon: BookOpen,
            permission: 'recipes.manage',
        },
    ]),
);

const purchasing = computed(() =>
    visible([
        {
            title: 'Purchase orders',
            href: purchaseOrdersIndex(),
            icon: FileText,
            permission:
                'purchase-orders.create|purchase-orders.approve|purchase-orders.receive',
        },
        {
            title: 'Deliveries',
            href: receiptsIndex(),
            icon: PackageCheck,
            permission: 'purchase-orders.receive|inventory.receive',
        },
        {
            title: 'Suppliers',
            href: suppliersIndex(),
            icon: Factory,
            permission: 'suppliers.manage',
        },
    ]),
);

const reports = computed(() =>
    visible([
        {
            title: 'Sales report',
            href: salesReport(),
            icon: ChartColumn,
            permission: 'reports.sales',
        },
        {
            title: 'Refunds & voids',
            href: showReport('refunds'),
            icon: Undo2,
            permission: 'reports.sales',
        },
        {
            title: 'Discounts given',
            href: showReport('discounts'),
            icon: BadgePercent,
            permission: 'reports.sales',
        },
        {
            title: 'Cash differences',
            href: showReport('cash-variance'),
            icon: Coins,
            permission: 'reports.sales',
        },
        {
            title: 'Stock in & out',
            href: showReport('stock-movements'),
            icon: ArrowLeftRight,
            permission: 'reports.inventory',
        },
        {
            title: 'Production report',
            href: showReport('production'),
            icon: Factory,
            permission: 'reports.inventory',
        },
        {
            title: 'Waste & rejects',
            href: showReport('waste'),
            icon: Trash2,
            permission: 'reports.inventory',
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
            title: 'Discounts',
            href: discountsIndex(),
            icon: BadgePercent,
            permission: 'settings.manage',
        },
        {
            title: 'Tax rules',
            href: taxRulesIndex(),
            icon: Landmark,
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
                v-if="production.length"
                label="Production"
                :items="production"
            />
            <NavMain
                v-if="purchasing.length"
                label="Purchasing"
                :items="purchasing"
            />
            <NavMain v-if="reports.length" label="Reports" :items="reports" />
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
