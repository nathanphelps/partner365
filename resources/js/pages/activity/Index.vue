<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import type { BreadcrumbItem } from '@/types';

type ActivityLog = {
    id: number;
    action: string;
    description: string;
    subject_type: string;
    subject_id: number;
    changes: Record<string, any> | null;
    user: { id: number; name: string } | null;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = {
    actions?: string[];
    user_id?: number | string;
    date_from?: string;
    date_to?: string;
    search?: string;
};

const props = defineProps<{
    logs: Paginated<ActivityLog>;
    filters: Filters;
    users: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Activity Log', href: activityIndex.url() },
];

const search = ref(props.filters.search ?? '');
const userId = ref(props.filters.user_id?.toString() ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const selectedAction = ref(
    props.filters.actions?.length ? props.filters.actions[0] : '',
);

const actionCategories: Record<string, string[]> = {
    Auth: [
        'user_logged_in',
        'user_logged_out',
        'login_failed',
        'account_locked',
        'password_changed',
        'two_factor_enabled',
        'two_factor_disabled',
    ],
    Partners: ['partner_created', 'partner_updated', 'partner_deleted'],
    Guests: [
        'guest_invited',
        'guest_updated',
        'guest_enabled',
        'guest_disabled',
        'guest_removed',
    ],
    Templates: ['template_created', 'template_updated', 'template_deleted'],
    Admin: [
        'settings_updated',
        'user_approved',
        'user_role_changed',
        'user_deleted',
        'graph_connection_tested',
        'consent_granted',
        'profile_updated',
        'account_deleted',
    ],
    Sync: [
        'sync_completed',
        'sync_triggered',
        'conditional_access_policies_synced',
    ],
    'Access Reviews': [
        'access_review_created',
        'access_review_completed',
        'access_review_decision_made',
        'access_review_remediation_applied',
    ],
    Entitlements: [
        'access_package_created',
        'access_package_updated',
        'access_package_deleted',
        'assignment_requested',
        'assignment_approved',
        'assignment_denied',
        'assignment_revoked',
    ],
};

function applyFilters() {
    const params: Record<string, string | string[]> = {};
    if (search.value) params.search = search.value;
    if (userId.value) params.user_id = userId.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    if (selectedAction.value) params['actions[]'] = [selectedAction.value];

    router.get(activityIndex.url(), params, { preserveState: true });
}

function clearFilters() {
    search.value = '';
    userId.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    selectedAction.value = '';
    router.get(activityIndex.url(), {}, { preserveState: true });
}

const hasFilters =
    props.filters.search ||
    props.filters.user_id ||
    props.filters.date_from ||
    props.filters.date_to ||
    (props.filters.actions && props.filters.actions.length > 0);

const actionVariant = (
    action: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (action.includes('created') || action.includes('invited'))
        return 'default';
    if (action.includes('deleted') || action.includes('removed'))
        return 'destructive';
    if (action.includes('updated') || action.includes('synced'))
        return 'secondary';
    return 'outline';
};

function formatDate(val: string): string {
    return new Date(val).toLocaleString();
}
</script>

<template>
    <Head title="Activity Log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold">Activity Log</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Audit trail of all actions taken in the system.
                </p>
            </div>

            <!-- Filters -->
            <div class="rounded-lg border bg-card p-4">
                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5"
                >
                    <div class="grid gap-1.5">
                        <Label for="search" class="text-xs">Search</Label>
                        <Input
                            id="search"
                            v-model="search"
                            placeholder="Search details..."
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="action" class="text-xs">Action</Label>
                        <Select v-model="selectedAction">
                            <SelectTrigger>
                                <SelectValue placeholder="All actions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All actions</SelectItem>
                                <template
                                    v-for="(
                                        actions, category
                                    ) in actionCategories"
                                    :key="category"
                                >
                                    <SelectItem
                                        v-for="action in actions"
                                        :key="action"
                                        :value="action"
                                    >
                                        {{ category }}: {{ action }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="user" class="text-xs">User</Label>
                        <Select v-model="userId">
                            <SelectTrigger>
                                <SelectValue placeholder="All users" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All users</SelectItem>
                                <SelectItem
                                    v-for="user in users"
                                    :key="user.id"
                                    :value="user.id.toString()"
                                >
                                    {{ user.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_from" class="text-xs">From</Label>
                        <Input id="date_from" v-model="dateFrom" type="date" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_to" class="text-xs">To</Label>
                        <Input id="date_to" v-model="dateTo" type="date" />
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <Button size="sm" @click="applyFilters">
                        Apply Filters
                    </Button>
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        size="sm"
                        @click="clearFilters"
                    >
                        Clear
                    </Button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Time
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                User
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Action
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Description
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="border-b transition-colors last:border-0 hover:bg-muted/30"
                        >
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="log.user" class="font-medium">{{
                                    log.user.name
                                }}</span>
                                <span
                                    v-else
                                    class="text-muted-foreground italic"
                                    >System</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="actionVariant(log.action)">
                                    {{ log.action }}
                                </Badge>
                            </td>
                            <td
                                class="max-w-md px-4 py-3 text-muted-foreground"
                            >
                                {{ log.description }}
                            </td>
                        </tr>
                        <tr v-if="logs.data.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No activity logs found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="logs.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ logs.data.length }} of {{ logs.total }} entries
                </p>
                <div class="flex gap-1">
                    <template v-for="link in logs.links" :key="link.label">
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
