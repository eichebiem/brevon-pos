<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChefHat,
    LayoutGrid,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Users,
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
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import { index as pos } from '@/routes/pos';
import { preparation } from '@/routes/staff';
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
            title: 'Preparation',
            href: preparation(),
            icon: ChefHat,
            permission: 'orders.update-status',
        },
    ]),
);

const administration = computed(() =>
    visible([
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
