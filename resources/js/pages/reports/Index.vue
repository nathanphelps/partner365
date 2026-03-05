<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    ComplianceSummary,
    GuestHealth,
    PartnerCompliance,
} from '@/types/compliance';

const props = defineProps<{
    summary: ComplianceSummary;
    partnerCompliance: PartnerCompliance;
    guestHealth: GuestHealth;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

// Partner filter
const partnerTab = ref('all');
const filteredPartners = computed(() => {
    const partners = props.partnerCompliance.partners;
    switch (partnerTab.value) {
        case 'no_mfa':
            return partners.filter((p) => !p.mfa_trust_enabled);
        case 'permissive':
            return partners.filter(
                (p) => p.b2b_inbound_enabled && p.b2b_outbound_enabled,
            );
        case 'no_ca':
            return partners.filter(
                (p) => p.conditional_access_policies_count === 0,
            );
        default:
            return partners;
    }
});

// Guest filter
const guestTab = ref('all');
const filteredGuests = computed(() => {
    const guests = props.guestHealth.guests;
    switch (guestTab.value) {
        case '30': {
            return guests.filter((g) => {
                if (!g.last_sign_in_at) return false;
                const d = daysSince(g.last_sign_in_at);
                return d >= 30 && d < 60;
            });
        }
        case '60': {
            return guests.filter((g) => {
                if (!g.last_sign_in_at) return false;
                const d = daysSince(g.last_sign_in_at);
                return d >= 60 && d < 90;
            });
        }
        case '90':
            return guests.filter(
                (g) => g.last_sign_in_at && daysSince(g.last_sign_in_at) >= 90,
            );
        case 'never':
            return guests.filter((g) => !g.last_sign_in_at);
        default:
            return guests;
    }
});

function daysSince(dateStr: string): number {
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
}

function daysInactiveLabel(lastSignIn: string | null): string {
    if (!lastSignIn) return 'Never';
    return `${daysSince(lastSignIn)}d`;
}

function inactiveBadgeVariant(
    lastSignIn: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!lastSignIn) return 'destructive';
    const days = daysSince(lastSignIn);
    if (days >= 90) return 'destructive';
    if (days >= 60) return 'secondary';
    if (days >= 30) return 'outline';
    return 'default';
}

function scoreColor(score: number): string {
    if (score >= 80) return 'text-green-600 dark:text-green-400';
    if (score >= 50) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

function formatDate(val: string | null): string {
    if (!val) return '--';
    return new Date(val).toLocaleDateString();
}
</script>

<template>
    <Head title="Compliance Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header with export -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Compliance Reports</h1>
                    <p class="text-sm text-muted-foreground">
                        Policy compliance and guest account health overview
                    </p>
                </div>
                <Button as-child variant="outline">
                    <a href="/reports/export" download>
                        <Download class="mr-2 h-4 w-4" />
                        Export CSV
                    </a>
                </Button>
            </div>

            <!-- Summary Cards -->
            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                        >
                            Compliance Score
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-4xl font-bold"
                            :class="scoreColor(summary.compliance_score)"
                        >
                            {{ summary.compliance_score }}%
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ summary.total_partners }} total partners
                            <template v-if="summary.avg_trust_score">
                                &middot; Avg trust score:
                                {{ summary.avg_trust_score }}
                            </template>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                        >
                            Partners with Issues
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-4xl font-bold">
                            {{ summary.partners_with_issues }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ partnerCompliance.no_mfa_count }} no MFA &middot;
                            {{ partnerCompliance.overly_permissive_count }}
                            overly permissive
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                        >
                            Stale Guests (90+ days)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-4xl font-bold">
                            {{ summary.stale_guests_90 }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ summary.total_guests }} total guests &middot;
                            {{ guestHealth.never_signed_in }} never signed in
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Section Tabs -->
            <Tabs default-value="partners">
                <TabsList>
                    <TabsTrigger value="partners">
                        Partner Compliance
                    </TabsTrigger>
                    <TabsTrigger value="guests">
                        Guest Health
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="partners">
            <!-- Partner Policy Compliance -->
            <Card>
                <CardHeader>
                    <CardTitle>Partner Policy Compliance</CardTitle>
                </CardHeader>
                <CardContent>
                    <Tabs v-model="partnerTab" class="mb-4">
                        <TabsList>
                            <TabsTrigger value="all">
                                All Issues ({{
                                    partnerCompliance.partners.length
                                }})
                            </TabsTrigger>
                            <TabsTrigger value="no_mfa">
                                No MFA ({{ partnerCompliance.no_mfa_count }})
                            </TabsTrigger>
                            <TabsTrigger value="permissive">
                                Overly Permissive ({{
                                    partnerCompliance.overly_permissive_count
                                }})
                            </TabsTrigger>
                            <TabsTrigger value="no_ca">
                                No CA Policies ({{
                                    partnerCompliance.no_ca_policies_count
                                }})
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Table v-if="filteredPartners.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Partner</TableHead>
                                <TableHead>MFA Trust</TableHead>
                                <TableHead>Device Trust</TableHead>
                                <TableHead>B2B Openness</TableHead>
                                <TableHead>CA Policies</TableHead>
                                <TableHead>Trust Score</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="partner in filteredPartners"
                                :key="partner.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/partners/${partner.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ partner.display_name }}
                                    </Link>
                                    <p
                                        v-if="partner.domain"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ partner.domain }}
                                    </p>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            partner.mfa_trust_enabled
                                                ? 'default'
                                                : 'destructive'
                                        "
                                    >
                                        {{
                                            partner.mfa_trust_enabled
                                                ? 'Enabled'
                                                : 'Disabled'
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            partner.device_trust_enabled
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            partner.device_trust_enabled
                                                ? 'Enabled'
                                                : 'Disabled'
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            partner.b2b_inbound_enabled &&
                                            partner.b2b_outbound_enabled
                                                ? 'destructive'
                                                : 'default'
                                        "
                                    >
                                        {{
                                            partner.b2b_inbound_enabled &&
                                            partner.b2b_outbound_enabled
                                                ? 'Both Open'
                                                : partner.b2b_inbound_enabled
                                                  ? 'Inbound Only'
                                                  : partner.b2b_outbound_enabled
                                                    ? 'Outbound Only'
                                                    : 'Restricted'
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            partner.conditional_access_policies_count >
                                            0
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            partner.conditional_access_policies_count
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <span v-if="partner.trust_score !== null">
                                        {{ partner.trust_score }}
                                    </span>
                                    <span v-else class="text-muted-foreground">
                                        --
                                    </span>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No compliance issues found.
                    </p>
                </CardContent>
            </Card>

                </TabsContent>

                <TabsContent value="guests">
            <!-- Guest Account Health -->
            <Card>
                <CardHeader>
                    <CardTitle>Guest Account Health</CardTitle>
                </CardHeader>
                <CardContent>
                    <!-- Stale breakdown -->
                    <div class="mb-4 flex flex-wrap gap-4">
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.stale_30_plus }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                30+ days
                            </p>
                        </div>
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.stale_60_plus }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                60+ days
                            </p>
                        </div>
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.stale_90_plus }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                90+ days
                            </p>
                        </div>
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.never_signed_in }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Never signed in
                            </p>
                        </div>
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.pending_invitations }}
                            </p>
                            <p class="text-xs text-muted-foreground">Pending</p>
                        </div>
                        <div
                            class="rounded-lg border bg-muted/30 px-4 py-2 text-center"
                        >
                            <p class="text-2xl font-bold">
                                {{ guestHealth.disabled_accounts }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Disabled
                            </p>
                        </div>
                    </div>

                    <Tabs v-model="guestTab" class="mb-4">
                        <TabsList>
                            <TabsTrigger value="all">
                                All Stale ({{ guestHealth.guests.length }})
                            </TabsTrigger>
                            <TabsTrigger value="30"> 30-59 Days </TabsTrigger>
                            <TabsTrigger value="60"> 60-89 Days </TabsTrigger>
                            <TabsTrigger value="90"> 90+ Days </TabsTrigger>
                            <TabsTrigger value="never">
                                Never Signed In
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <Table v-if="filteredGuests.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Guest</TableHead>
                                <TableHead>Partner</TableHead>
                                <TableHead>Last Sign-In</TableHead>
                                <TableHead>Days Inactive</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="guest in filteredGuests"
                                :key="guest.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/guests/${guest.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ guest.email }}
                                    </Link>
                                    <p
                                        v-if="guest.display_name"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ guest.display_name }}
                                    </p>
                                </TableCell>
                                <TableCell>
                                    <Link
                                        v-if="guest.partner_organization"
                                        :href="`/partners/${guest.partner_organization.id}`"
                                        class="text-sm hover:underline"
                                    >
                                        {{
                                            guest.partner_organization
                                                .display_name
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">
                                        --
                                    </span>
                                </TableCell>
                                <TableCell>
                                    {{ formatDate(guest.last_sign_in_at) }}
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            inactiveBadgeVariant(
                                                guest.last_sign_in_at,
                                            )
                                        "
                                    >
                                        {{
                                            daysInactiveLabel(
                                                guest.last_sign_in_at,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            guest.account_enabled
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            guest.account_enabled
                                                ? 'Enabled'
                                                : 'Disabled'
                                        }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No stale guests found.
                    </p>
                </CardContent>
            </Card>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
