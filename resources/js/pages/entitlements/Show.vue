<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { AccessPackage } from '@/types/entitlement';

const props = defineProps<{
    package: AccessPackage;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
    {
        title: props.package.display_name,
        href: `/entitlements/${props.package.id}`,
    },
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
        pending_approval: 'outline',
        approved: 'secondary',
        delivering: 'secondary',
        delivered: 'default',
        denied: 'destructive',
        expired: 'destructive',
        revoked: 'destructive',
    };
    return map[status] ?? 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const assignForm = useForm({
    target_user_email: '',
    justification: '',
});

function submitAssignment() {
    assignForm.post(`/entitlements/${props.package.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
}

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deletePackage() {
    deleting.value = true;
    router.delete(`/entitlements/${props.package.id}`, {
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="package.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">
                        {{ package.display_name }}
                    </h1>
                    <p
                        v-if="package.description"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ package.description }}
                    </p>
                </div>
                <Badge :variant="package.is_active ? 'default' : 'secondary'">
                    {{ package.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Partner</span>
                    <span>{{
                        package.partner_organization?.display_name ?? '\u2014'
                    }}</span>

                    <span class="text-muted-foreground">Catalog</span>
                    <span>{{ package.catalog?.display_name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Duration</span>
                    <span>{{ package.duration_days }} days</span>

                    <span class="text-muted-foreground">Approval Required</span>
                    <span>{{ package.approval_required ? 'Yes' : 'No' }}</span>

                    <span class="text-muted-foreground">Approver</span>
                    <span>{{ package.approver?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created By</span>
                    <span>{{ package.created_by?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created</span>
                    <span>{{ formatDate(package.created_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Resources</CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="package.resources?.length"
                        class="flex flex-col gap-2"
                    >
                        <div
                            v-for="r in package.resources"
                            :key="r.id"
                            class="flex items-center gap-2 rounded border p-2"
                        >
                            <Badge variant="outline">{{
                                r.resource_type === 'group'
                                    ? 'Group'
                                    : 'SharePoint'
                            }}</Badge>
                            <span class="text-sm">{{
                                r.resource_display_name
                            }}</span>
                        </div>
                    </div>
                    <p
                        v-else
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        No resources configured.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="canManage">
                <CardHeader>
                    <CardTitle>Request Assignment</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitAssignment"
                    >
                        <div>
                            <Label for="email">External User Email</Label>
                            <Input
                                id="email"
                                type="email"
                                v-model="assignForm.target_user_email"
                                placeholder="partner@external.com"
                            />
                            <p
                                v-if="assignForm.errors.target_user_email"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ assignForm.errors.target_user_email }}
                            </p>
                        </div>
                        <div>
                            <Label for="justification">Justification</Label>
                            <Textarea
                                id="justification"
                                v-model="assignForm.justification"
                                placeholder="Optional justification..."
                            />
                        </div>
                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                :disabled="
                                    assignForm.processing ||
                                    !assignForm.target_user_email
                                "
                            >
                                {{
                                    assignForm.processing
                                        ? 'Requesting...'
                                        : 'Request Assignment'
                                }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Assignments</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table v-if="package.assignments?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Email</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Requested</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>Approved By</TableHead>
                                <TableHead v-if="canManage"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="a in package.assignments"
                                :key="a.id"
                            >
                                <TableCell class="font-medium">{{
                                    a.target_user_email
                                }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(a.status)">
                                        {{ statusLabel(a.status) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{
                                    formatDate(a.requested_at)
                                }}</TableCell>
                                <TableCell>{{
                                    formatDate(a.expires_at)
                                }}</TableCell>
                                <TableCell>{{
                                    a.approved_by?.name ?? '\u2014'
                                }}</TableCell>
                                <TableCell v-if="canManage">
                                    <div class="flex gap-1">
                                        <Button
                                            v-if="
                                                a.status === 'pending_approval'
                                            "
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                router.post(
                                                    `/entitlements/${package.id}/assignments/${a.id}/approve`,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            "
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            v-if="
                                                a.status === 'pending_approval'
                                            "
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                router.post(
                                                    `/entitlements/${package.id}/assignments/${a.id}/deny`,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            "
                                        >
                                            Deny
                                        </Button>
                                        <Button
                                            v-if="a.status === 'delivered'"
                                            variant="destructive"
                                            size="sm"
                                            @click="
                                                router.post(
                                                    `/entitlements/${package.id}/assignments/${a.id}/revoke`,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            "
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No assignments yet.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="isAdmin" class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="mb-3 text-sm text-muted-foreground">
                            Delete this access package, its resources, and all
                            assignments.
                        </p>
                        <Button
                            variant="destructive"
                            @click="showDeleteConfirm = true"
                        >
                            Delete Package
                        </Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">
                            Are you sure? This cannot be undone.
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="destructive"
                                :disabled="deleting"
                                @click="deletePackage"
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
