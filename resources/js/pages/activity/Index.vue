<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
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
    user: { name: string } | null;
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

defineProps<{
    logs: Paginated<ActivityLog>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Activity Log', href: activityIndex.url() },
];

const actionVariant = (action: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (action.includes('created') || action.includes('invited')) return 'default';
    if (action.includes('deleted') || action.includes('removed')) return 'destructive';
    if (action.includes('updated') || action.includes('synced')) return 'secondary';
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
                <p class="text-sm text-muted-foreground mt-1">
                    Audit trail of all actions taken in the system.
                </p>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Time</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">User</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Action</th>
                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="border-b last:border-0 hover:bg-muted/30 transition-colors"
                        >
                            <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="log.user" class="font-medium">{{ log.user.name }}</span>
                                <span v-else class="text-muted-foreground italic">System</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="actionVariant(log.action)">
                                    {{ log.action }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground max-w-md">
                                {{ log.description }}
                            </td>
                        </tr>
                        <tr v-if="logs.data.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">
                                No activity logs found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="logs.last_page > 1" class="flex items-center justify-between">
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
                                    ? 'bg-primary text-primary-foreground font-medium'
                                    : 'border hover:bg-muted',
                            ]"
                            v-html="link.label"
                        />
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
