<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
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
import type { ConditionalAccessPolicy } from '@/types/conditional-access';

const props = defineProps<{
    policy: ConditionalAccessPolicy;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Conditional Access', href: '/conditional-access' },
    {
        title: props.policy.display_name,
        href: `/conditional-access/${props.policy.id}`,
    },
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

function formatUserTypes(types: string | null): string {
    if (!types) return '\u2014';
    const labels: Record<string, string> = {
        b2bCollaborationGuest: 'B2B Collaboration Guest',
        b2bCollaborationMember: 'B2B Collaboration Member',
        b2bDirectConnectUser: 'B2B Direct Connect User',
        internalGuest: 'Internal Guest',
        serviceProvider: 'Service Provider',
        otherExternalUser: 'Other External User',
    };
    return types
        .split(',')
        .map((t) => labels[t.trim()] ?? t.trim())
        .join(', ');
}

function formatControls(controls: string[] | null): string {
    if (!controls || controls.length === 0) return 'None';
    const labels: Record<string, string> = {
        mfa: 'Require MFA',
        compliantDevice: 'Require compliant device',
        domainJoinedDevice: 'Require domain joined device',
        approvedApplication: 'Require approved app',
        compliantApplication: 'Require compliant app',
        passwordChange: 'Require password change',
        block: 'Block access',
    };
    return controls.map((c) => labels[c] ?? c).join(', ');
}

const entraUrl = `https://entra.microsoft.com/#view/Microsoft_AAD_ConditionalAccess/PolicyBlade/policyId/${props.policy.policy_id}`;
</script>

<template>
    <Head :title="policy.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ policy.display_name }}
                        </h1>
                        <Badge :variant="stateVariant(policy.state)">
                            {{ stateLabel(policy.state) }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Policy ID: {{ policy.policy_id }}
                    </p>
                </div>
                <a :href="entraUrl" target="_blank" rel="noopener noreferrer">
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        View in Entra
                    </Button>
                </a>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Policy Details</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground"
                        >Targeted User Types</span
                    >
                    <span>{{
                        formatUserTypes(policy.guest_or_external_user_types)
                    }}</span>

                    <span class="text-muted-foreground"
                        >External Tenant Scope</span
                    >
                    <span>{{
                        policy.external_tenant_scope === 'all'
                            ? 'All external tenants'
                            : `Specific tenants (${policy.external_tenant_ids?.length ?? 0})`
                    }}</span>

                    <span class="text-muted-foreground"
                        >Target Applications</span
                    >
                    <span>{{
                        policy.target_applications === 'all'
                            ? 'All applications'
                            : policy.target_applications
                    }}</span>

                    <span class="text-muted-foreground">Grant Controls</span>
                    <span>{{ formatControls(policy.grant_controls) }}</span>

                    <span class="text-muted-foreground">Session Controls</span>
                    <span>{{ formatControls(policy.session_controls) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Affected Partners ({{
                            policy.partners?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="policy.partners?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Partner</TableHead>
                                <TableHead>Domain</TableHead>
                                <TableHead>Matched User Type</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="partner in policy.partners"
                                :key="partner.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/partners/${partner.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ partner.display_name }}
                                    </Link>
                                </TableCell>
                                <TableCell>{{
                                    partner.domain ?? '\u2014'
                                }}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {{ partner.pivot.matched_user_type }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No partners matched by this policy.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
