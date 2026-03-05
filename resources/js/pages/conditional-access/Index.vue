<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
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
import type { ConditionalAccessPolicy } from '@/types/conditional-access';
import type { Paginated } from '@/types/partner';

defineProps<{
    policies: Paginated<ConditionalAccessPolicy>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Conditional Access', href: '/conditional-access' },
];

function stateVariant(
    state: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        enabled: 'default',
        disabled: 'outline',
        enabledForReportingButNotEnforced: 'secondary',
    };
    return map[state] ?? 'outline';
}

function stateLabel(state: string): string {
    const map: Record<string, string> = {
        enabled: 'Enabled',
        disabled: 'Disabled',
        enabledForReportingButNotEnforced: 'Report-only',
    };
    return map[state] ?? state;
}

function formatGrantControls(controls: string[] | null): string {
    if (!controls || controls.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        mfa: 'MFA',
        compliantDevice: 'Compliant device',
        domainJoinedDevice: 'Domain joined',
        approvedApplication: 'Approved app',
        compliantApplication: 'Compliant app',
        passwordChange: 'Password change',
        block: 'Block',
    };
    return controls.map((c) => labels[c] ?? c).join(', ');
}
</script>

<template>
    <Head title="Conditional Access" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">Conditional Access</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Conditional Access policies targeting guest and external
                    users.
                </p>
            </div>

            <div
                v-if="uncoveredPartnerCount > 0"
                class="flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 dark:border-yellow-700 dark:bg-yellow-950"
            >
                <AlertTriangle
                    class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400"
                />
                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                    <strong>{{ uncoveredPartnerCount }}</strong>
                    partner{{ uncoveredPartnerCount === 1 ? '' : 's' }} ha{{
                        uncoveredPartnerCount === 1 ? 's' : 've'
                    }}
                    no Conditional Access policies targeting their guests.
                </p>
            </div>

            <Card v-if="policies.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No Conditional Access policies targeting guest users found.
                    Run the sync command or wait for the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Policy Name</TableHead>
                        <TableHead>State</TableHead>
                        <TableHead>Grant Controls</TableHead>
                        <TableHead>Target Apps</TableHead>
                        <TableHead>Affected Partners</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="policy in policies.data" :key="policy.id">
                        <TableCell>
                            <Link
                                :href="`/conditional-access/${policy.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ policy.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="stateVariant(policy.state)">
                                {{ stateLabel(policy.state) }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{
                            formatGrantControls(policy.grant_controls)
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    policy.target_applications === 'all'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    policy.target_applications === 'all'
                                        ? 'All apps'
                                        : policy.target_applications
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ policy.partners_count ?? 0 }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
