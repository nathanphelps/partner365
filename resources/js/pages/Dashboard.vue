<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import accessReviews from '@/routes/access-reviews';
import { index as activityIndex } from '@/routes/activity';
import entitlements from '@/routes/entitlements';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import reports from '@/routes/reports';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    stats: {
        total_partners: number;
        total_guests: number;
        pending_invitations: number;
        stale_guests: number;
        overdue_reviews: number;
    };
    pendingApprovals: {
        id: number;
        access_package_id: number;
        access_package_name: string | null;
        target_user_email: string;
        requested_at: string | null;
    }[];
    attentionPartners: {
        id: number;
        display_name: string;
        trust_score: number;
        stale_guests_count: number;
    }[];
    recentActivity: {
        id: number;
        action: string;
        description: string;
        user: { name: string } | null;
        created_at: string;
    }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
];

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

function timeAgo(val: string): string {
    const diff = Date.now() - new Date(val).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function approveAssignment(accessPackageId: number, assignmentId: number) {
    router.post(
        entitlements.assignments.approve.url({
            entitlement: accessPackageId,
            assignment: assignmentId,
        }),
        {},
        { preserveScroll: true },
    );
}

function denyAssignment(accessPackageId: number, assignmentId: number) {
    router.post(
        entitlements.assignments.deny.url({
            entitlement: accessPackageId,
            assignment: assignmentId,
        }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Stat Cards -->
            <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Total Partners</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.total_partners }}
                        </p>
                        <Link
                            :href="partners.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View all partners &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Total Guests</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.total_guests }}
                        </p>
                        <Link
                            :href="guests.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View all guests &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Stale Guests</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-3xl font-bold"
                            :class="
                                stats.stale_guests > 0 ? 'text-amber-600' : ''
                            "
                        >
                            {{ stats.stale_guests }}
                        </p>
                        <Link
                            :href="reports.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View report &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Pending Invitations</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.pending_invitations }}
                        </p>
                        <Link
                            :href="
                                guests.index.url() +
                                '?status=pending_acceptance'
                            "
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View pending &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Overdue Reviews</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-3xl font-bold"
                            :class="
                                stats.overdue_reviews > 0 ? 'text-red-600' : ''
                            "
                        >
                            {{ stats.overdue_reviews }}
                        </p>
                        <Link
                            :href="accessReviews.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View reviews &rarr;
                        </Link>
                    </CardContent>
                </Card>
            </div>

            <!-- Triage Section -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Pending Approvals -->
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-3"
                    >
                        <CardTitle>Pending Approvals</CardTitle>
                        <Link
                            :href="entitlements.index.url()"
                            class="text-xs text-muted-foreground hover:underline"
                        >
                            View all &rarr;
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="pendingApprovals.length > 0"
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="approval in pendingApprovals"
                                :key="approval.id"
                                class="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ approval.target_user_email }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            approval.access_package_name ??
                                            'Unknown package'
                                        }}
                                        <span v-if="approval.requested_at">
                                            &middot;
                                            {{ timeAgo(approval.requested_at) }}
                                        </span>
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <Button
                                        size="sm"
                                        variant="default"
                                        @click="
                                            approveAssignment(
                                                approval.access_package_id,
                                                approval.id,
                                            )
                                        "
                                    >
                                        Approve
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            denyAssignment(
                                                approval.access_package_id,
                                                approval.id,
                                            )
                                        "
                                    >
                                        Deny
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-6 text-muted-foreground"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <p class="text-sm">No pending approvals</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Partners Needing Attention -->
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-3"
                    >
                        <CardTitle>Partners Needing Attention</CardTitle>
                        <Link
                            :href="reports.index.url()"
                            class="text-xs text-muted-foreground hover:underline"
                        >
                            View report &rarr;
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="attentionPartners.length > 0"
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="partner in attentionPartners"
                                :key="partner.id"
                                class="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <Link
                                        :href="partners.show.url(partner.id)"
                                        class="truncate text-sm font-medium hover:underline"
                                    >
                                        {{ partner.display_name }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">
                                        {{ partner.stale_guests_count }} stale
                                        guest{{
                                            partner.stale_guests_count !== 1
                                                ? 's'
                                                : ''
                                        }}
                                    </p>
                                </div>
                                <Badge
                                    :variant="
                                        partner.trust_score < 50
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                >
                                    {{ partner.trust_score }}
                                </Badge>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-6 text-muted-foreground"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <p class="text-sm">All partners in good standing</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Activity -->
            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-3"
                >
                    <CardTitle>Recent Activity</CardTitle>
                    <Link
                        :href="activityIndex.url()"
                        class="text-xs text-muted-foreground hover:underline"
                    >
                        View all &rarr;
                    </Link>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="recentActivity.length > 0"
                        class="flex flex-col gap-3"
                    >
                        <div
                            v-for="log in recentActivity"
                            :key="log.id"
                            class="flex items-start gap-3 border-b pb-3 last:border-0 last:pb-0"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge
                                        :variant="actionVariant(log.action)"
                                        class="text-xs"
                                    >
                                        {{ log.action }}
                                    </Badge>
                                    <span class="text-xs text-muted-foreground">
                                        {{ log.user?.name ?? 'System' }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1 truncate text-sm text-muted-foreground"
                                >
                                    {{ log.description }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                            >
                                {{ timeAgo(log.created_at) }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        No recent activity.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
