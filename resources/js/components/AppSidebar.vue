<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    ClipboardCheck,
    FileStack,
    LayoutGrid,
    Package,
    Settings,
    Shield,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Partners', href: '/partners', icon: Building2 },
        { title: 'Guests', href: '/guests', icon: Users },
        { title: 'Templates', href: '/templates', icon: FileStack },
        {
            title: 'Access Reviews',
            href: '/access-reviews',
            icon: ClipboardCheck,
        },
        {
            title: 'Conditional Access',
            href: '/conditional-access',
            icon: Shield,
        },
        {
            title: 'Entitlements',
            href: '/entitlements',
            icon: Package,
        },
        { title: 'Activity', href: '/activity', icon: Activity },
    ];

    if (isAdmin.value) {
        items.push({ title: 'Admin', href: '/admin/graph', icon: Settings });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="p-3 pb-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="pt-2">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="p-3">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
