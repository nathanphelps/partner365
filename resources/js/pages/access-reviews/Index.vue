<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { AccessReview, AccessReviewInstance } from '@/types/access-review';
import type { Paginated } from '@/types/partner';

defineProps<{
    reviews: Paginated<AccessReview>;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function reviewTypeLabel(type: string): string {
    return type === 'guest_users' ? 'Guest Users' : 'Partner Organizations';
}

function statusVariant(
    status: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        pending: 'outline',
        in_progress: 'secondary',
        completed: 'default',
        expired: 'destructive',
    };
    return map[status] ?? 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function compliancePercent(instance?: AccessReviewInstance): string {
    if (!instance || !instance.decisions_count) return '\u2014';
    const decided =
        (instance.approved_count ?? 0) + (instance.denied_count ?? 0);
    return Math.round((decided / instance.decisions_count) * 100) + '%';
}
</script>

<template>
    <Head title="Access Reviews" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Access Reviews</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Periodic certification of guest user and partner
                        organization access.
                    </p>
                </div>
                <Link v-if="isAdmin" href="/access-reviews/create">
                    <Button>Create Review</Button>
                </Link>
            </div>

            <Card v-if="reviews.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No access reviews configured yet.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Recurrence</TableHead>
                        <TableHead>Reviewer</TableHead>
                        <TableHead>Latest Status</TableHead>
                        <TableHead>Compliance</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="review in reviews.data" :key="review.id">
                        <TableCell>
                            <Link
                                :href="`/access-reviews/${review.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ review.title }}
                            </Link>
                        </TableCell>
                        <TableCell>{{
                            reviewTypeLabel(review.review_type)
                        }}</TableCell>
                        <TableCell>
                            <span v-if="review.recurrence_type === 'recurring'">
                                Every {{ review.recurrence_interval_days }}d
                            </span>
                            <span v-else>One-time</span>
                        </TableCell>
                        <TableCell>{{
                            review.reviewer?.name ?? '\u2014'
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                v-if="review.latest_instance"
                                :variant="
                                    statusVariant(review.latest_instance.status)
                                "
                            >
                                {{ statusLabel(review.latest_instance.status) }}
                            </Badge>
                            <span v-else class="text-muted-foreground"
                                >&mdash;</span
                            >
                        </TableCell>
                        <TableCell>{{
                            compliancePercent(review.latest_instance)
                        }}</TableCell>
                        <TableCell>{{
                            formatDate(review.created_at)
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
