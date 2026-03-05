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
import type { AccessPackage } from '@/types/entitlement';
import type { Paginated } from '@/types/partner';

defineProps<{
    packages: Paginated<AccessPackage>;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function statusVariant(
    active: boolean,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    return active ? 'default' : 'secondary';
}
</script>

<template>
    <Head title="Entitlements" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Access Packages</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Self-service access packages for external partner users.
                    </p>
                </div>
                <Link v-if="isAdmin" href="/entitlements/create">
                    <Button>Create Package</Button>
                </Link>
            </div>

            <Card v-if="packages.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No access packages configured yet.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Partner</TableHead>
                        <TableHead>Resources</TableHead>
                        <TableHead>Assignments</TableHead>
                        <TableHead>Duration</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="pkg in packages.data" :key="pkg.id">
                        <TableCell>
                            <Link
                                :href="`/entitlements/${pkg.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ pkg.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell>{{
                            pkg.partner_organization?.display_name ?? '\u2014'
                        }}</TableCell>
                        <TableCell>{{ pkg.resources_count ?? 0 }}</TableCell>
                        <TableCell>{{ pkg.assignments_count ?? 0 }}</TableCell>
                        <TableCell>{{ pkg.duration_days }}d</TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(pkg.is_active)">
                                {{ pkg.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ formatDate(pkg.created_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
