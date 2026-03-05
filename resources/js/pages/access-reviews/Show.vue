<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
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
import type { AccessReview } from '@/types/access-review';

const props = defineProps<{
    review: AccessReview;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: props.review.title, href: `/access-reviews/${props.review.id}` },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
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

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deleteReview() {
    deleting.value = true;
    router.delete(`/access-reviews/${props.review.id}`, {
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="review.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">{{ review.title }}</h1>
                    <p
                        v-if="review.description"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ review.description }}
                    </p>
                </div>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Type</span>
                    <span>{{
                        review.review_type === 'guest_users'
                            ? 'Guest Users'
                            : 'Partner Organizations'
                    }}</span>

                    <span class="text-muted-foreground">Scope</span>
                    <span>{{
                        review.scope_partner?.display_name ?? 'All'
                    }}</span>

                    <span class="text-muted-foreground">Recurrence</span>
                    <span>
                        <template v-if="review.recurrence_type === 'recurring'"
                            >Every
                            {{ review.recurrence_interval_days }} days</template
                        >
                        <template v-else>One-time</template>
                    </span>

                    <span class="text-muted-foreground">Remediation</span>
                    <span>{{ statusLabel(review.remediation_action) }}</span>

                    <span class="text-muted-foreground">Reviewer</span>
                    <span>{{ review.reviewer?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created By</span>
                    <span>{{ review.created_by?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Next Review</span>
                    <span>{{ formatDate(review.next_review_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Review Instances</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table v-if="review.instances?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Started</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Completed</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="inst in review.instances"
                                :key="inst.id"
                            >
                                <TableCell>{{
                                    formatDate(inst.started_at)
                                }}</TableCell>
                                <TableCell>{{
                                    formatDate(inst.due_at)
                                }}</TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="statusVariant(inst.status)"
                                        >{{ statusLabel(inst.status) }}</Badge
                                    >
                                </TableCell>
                                <TableCell>{{
                                    formatDate(inst.completed_at)
                                }}</TableCell>
                                <TableCell>
                                    <Link
                                        :href="`/access-reviews/${review.id}/instances/${inst.id}`"
                                    >
                                        <Button variant="outline" size="sm"
                                            >View Decisions</Button
                                        >
                                    </Link>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No instances yet.
                    </p>
                </CardContent>
            </Card>

            <Card class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="mb-3 text-sm text-muted-foreground">
                            Delete this access review and all its instances and
                            decisions.
                        </p>
                        <Button
                            variant="destructive"
                            @click="showDeleteConfirm = true"
                            >Delete Review</Button
                        >
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">
                            Are you sure? This cannot be undone.
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="destructive"
                                @click="deleteReview"
                                :disabled="deleting"
                            >
                                {{
                                    deleting ? 'Deleting\u2026' : 'Yes, Delete'
                                }}
                            </Button>
                            <Button
                                variant="outline"
                                @click="showDeleteConfirm = false"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
