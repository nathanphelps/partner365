<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-3 py-0">
        <SidebarMenu class="gap-1.5">
            <SidebarMenuItem
                v-for="item in items"
                :key="item.title"
                class="relative"
            >
                <SidebarMenuButton
                    as-child
                    size="lg"
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" class="!size-5" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <div
                    v-if="isCurrentUrl(item.href)"
                    class="absolute top-1/2 left-0 h-6 w-[3px] -translate-y-1/2 rounded-r-full bg-primary"
                />
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
