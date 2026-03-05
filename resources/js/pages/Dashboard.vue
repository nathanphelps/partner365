<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    stats: {
        total_partners: number;
        mfa_trust_enabled: number;
        mfa_trust_disabled: number;
        total_guests: number;
        pending_invitations: number;
        inactive_guests: number;
        partners_by_category: Record<string, number>;
    };
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

const categoryLabel: Record<string, string> = {
    vendor: 'Vendor',
    contractor: 'Contractor',
    strategic_partner: 'Strategic Partner',
    customer: 'Customer',
    other: 'Other',
};

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
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Stat Cards -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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
                            >MFA Trust Enabled</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.mfa_trust_enabled }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ stats.mfa_trust_disabled }} partner{{
                                stats.mfa_trust_disabled !== 1 ? 's' : ''
                            }}
                            without MFA trust
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <!-- Partners by Category -->
                <Card>
                    <CardHeader>
                        <CardTitle>Partners by Category</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="
                                Object.keys(stats.partners_by_category).length >
                                0
                            "
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="(
                                    count, category
                                ) in stats.partners_by_category"
                                :key="category"
                                class="flex items-center justify-between"
                            >
                                <div class="flex items-center gap-2">
                                    <Badge variant="secondary">
                                        {{
                                            categoryLabel[category] ?? category
                                        }}
                                    </Badge>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div
                                        class="h-2 w-32 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-2 rounded-full bg-primary"
                                            :style="`width: ${stats.total_partners > 0 ? Math.round((count / stats.total_partners) * 100) : 0}%`"
                                        />
                                    </div>
                                    <span
                                        class="w-6 text-right text-sm font-medium"
                                        >{{ count }}</span
                                    >
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No partners yet.
                        </p>
                    </CardContent>
                </Card>

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
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Badge
                                            :variant="actionVariant(log.action)"
                                            class="text-xs"
                                        >
                                            {{ log.action }}
                                        </Badge>
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
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
        </div>
    </AppLayout>
</template>
