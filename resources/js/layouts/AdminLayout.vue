<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Cable, Globe, Settings2, Users } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const adminNavItems: NavItem[] = [
    { title: 'Microsoft Graph', href: '/admin/graph', icon: Cable },
    { title: 'Collaboration', href: '/admin/collaboration', icon: Globe },
    { title: 'User Management', href: '/admin/users', icon: Users },
    { title: 'Sync Settings', href: '/admin/sync', icon: Settings2 },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading
                title="Administration"
                description="System configuration and user management"
            />

            <div class="flex flex-col lg:flex-row lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav
                        class="flex flex-col space-y-1 space-x-0"
                        aria-label="Admin"
                    >
                        <Button
                            v-for="item in adminNavItems"
                            :key="item.href as string"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                { 'bg-muted': isCurrentOrParentUrl(item.href) },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                <component
                                    :is="item.icon"
                                    class="mr-2 h-4 w-4"
                                />
                                {{ item.title }}
                            </Link>
                        </Button>
                    </nav>
                </aside>

                <Separator class="my-6 lg:hidden" />

                <div class="flex-1">
                    <section class="space-y-12">
                        <slot />
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
