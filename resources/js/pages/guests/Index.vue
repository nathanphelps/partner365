<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import guestRoutes from '@/routes/guests';
import partnerRoutes from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { GuestUser, Paginated } from '@/types/partner';

const props = defineProps<{
    guests: Paginated<GuestUser>;
    filters?: { search?: string; status?: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guestRoutes.index.url() },
];

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');

let searchTimer: ReturnType<typeof setTimeout>;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            guestRoutes.index.url(),
            { search: val, status: statusFilter.value },
            { preserveState: true, replace: true },
        );
    }, 400);
});

watch(statusFilter, (val) => {
    router.get(
        guestRoutes.index.url(),
        { search: search.value, status: val },
        { preserveState: true, replace: true },
    );
});

const statusVariant = (
    status: string,
): 'default' | 'destructive' | 'outline' => {
    if (status === 'accepted') return 'default';
    if (status === 'failed') return 'destructive';
    return 'outline';
};

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString();
}
</script>

<template>
    <Head title="Guest Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Guest Users</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage external guest users in your M365 tenant.
                    </p>
                </div>
                <Link :href="guestRoutes.create.url()">
                    <Button>Invite Guest</Button>
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex gap-3">
                <Input
                    v-model="search"
                    placeholder="Search by name or email..."
                    class="max-w-sm"
                />
                <select
                    v-model="statusFilter"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                >
                    <option value="">All Statuses</option>
                    <option value="pending_acceptance">
                        Pending Acceptance
                    </option>
                    <option value="accepted">Accepted</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Name
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Email
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Partner Org
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Status
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Last Sign In
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Created
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="guest in guests.data"
                            :key="guest.id"
                            class="border-b transition-colors last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="guestRoutes.show.url(guest.id)"
                                    class="font-medium text-foreground hover:underline"
                                >
                                    {{ guest.display_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ guest.email }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="guest.partner_organization"
                                    :href="
                                        partnerRoutes.show.url(
                                            guest.partner_organization.id,
                                        )
                                    "
                                    class="text-sm hover:underline"
                                >
                                    {{
                                        guest.partner_organization.display_name
                                    }}
                                </Link>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <Badge
                                    :variant="
                                        statusVariant(guest.invitation_status)
                                    "
                                >
                                    {{ statusLabel(guest.invitation_status) }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(guest.last_sign_in_at) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(guest.created_at) }}
                            </td>
                        </tr>
                        <tr v-if="guests.data.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No guest users found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="guests.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ guests.data.length }} of
                    {{ guests.total }} guests
                </p>
                <div class="flex gap-1">
                    <template v-for="link in guests.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                link.active
                                    ? 'bg-primary font-medium text-primary-foreground'
                                    : 'border hover:bg-muted',
                            ]"
                            ><!-- eslint-disable-next-line vue/no-v-html --><span
                                v-html="link.label"
                        /></Link>
                        <span
                            v-else
                            class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
