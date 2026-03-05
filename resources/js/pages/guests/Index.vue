<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import GuestUserTable from '@/components/GuestUserTable.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import guestRoutes from '@/routes/guests';
import type { BreadcrumbItem } from '@/types';
import type { GuestUser, Paginated } from '@/types/partner';

defineProps<{
    guests: Paginated<GuestUser>;
    filters?: {
        search?: string;
        status?: string;
        account_enabled?: string;
        partner_id?: string;
    };
    partners?: { id: number; display_name: string }[];
    canManage: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guestRoutes.index.url() },
];
</script>

<template>
    <Head title="Guest Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Guest Users</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage external guest users in your M365 tenant.
                    </p>
                </div>
                <Link v-if="canManage" :href="guestRoutes.create.url()">
                    <Button>Invite Guest</Button>
                </Link>
            </div>

            <GuestUserTable
                :guests="guests"
                :can-manage="canManage"
                :filters="filters"
                :partners="partners"
            />
        </div>
    </AppLayout>
</template>
