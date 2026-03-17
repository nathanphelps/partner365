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
import type { Paginated } from '@/types/partner';
import type { SharePointSite } from '@/types/sharepoint';

defineProps<{
    sites: Paginated<SharePointSite>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'SharePoint Sites', href: '/sharepoint-sites' },
];

function sharingVariant(
    capability: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        Disabled: 'outline',
        ExistingExternalUserSharingOnly: 'secondary',
        ExternalUserSharingOnly: 'secondary',
        ExternalUserAndGuestSharing: 'default',
    };
    return map[capability] ?? 'outline';
}

function sharingLabel(capability: string): string {
    const map: Record<string, string> = {
        Disabled: 'Disabled',
        ExistingExternalUserSharingOnly: 'Existing external users',
        ExternalUserSharingOnly: 'External users only',
        ExternalUserAndGuestSharing: 'External users & guests',
    };
    return map[capability] ?? capability;
}

function accessPolicyLabel(policy: string | null): string {
    if (!policy) return 'None';
    const map: Record<string, string> = {
        AllowFullAccess: 'Full Access',
        AllowLimitedAccess: 'Limited Access',
        BlockAccess: 'Block Access',
        AuthenticationContext: 'Auth Context',
    };
    return map[policy] ?? policy;
}

function accessPolicyVariant(
    policy: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!policy) return 'outline';
    const map: Record<
        string,
        'default' | 'secondary' | 'destructive' | 'outline'
    > = {
        AllowFullAccess: 'default',
        AllowLimitedAccess: 'secondary',
        BlockAccess: 'destructive',
        AuthenticationContext: 'secondary',
    };
    return map[policy] ?? 'outline';
}
</script>

<template>
    <Head title="SharePoint Sites" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">SharePoint Sites</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    SharePoint sites with external sharing and guest user
                    access.
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
                    no guest users with SharePoint site access.
                </p>
            </div>

            <Card v-if="sites.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No SharePoint sites found. Run the sync command or wait for
                    the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Site Name</TableHead>
                        <TableHead>URL</TableHead>
                        <TableHead>Sharing</TableHead>
                        <TableHead>Access Policy</TableHead>
                        <TableHead>Sensitivity Label</TableHead>
                        <TableHead>Guest Permissions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="site in sites.data" :key="site.id">
                        <TableCell>
                            <Link
                                :href="`/sharepoint-sites/${site.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ site.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell
                            class="max-w-xs truncate text-xs text-muted-foreground"
                        >
                            {{ site.url }}
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    sharingVariant(
                                        site.external_sharing_capability,
                                    )
                                "
                            >
                                {{
                                    sharingLabel(
                                        site.external_sharing_capability,
                                    )
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    accessPolicyVariant(
                                        site.conditional_access_policy,
                                    )
                                "
                            >
                                {{
                                    accessPolicyLabel(
                                        site.conditional_access_policy,
                                    )
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <template v-if="site.sensitivity_label">
                                <span
                                    v-if="site.sensitivity_label.color"
                                    class="mr-1.5 inline-block size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            site.sensitivity_label.color,
                                    }"
                                />
                                {{ site.sensitivity_label.name }}
                            </template>
                            <span v-else class="text-muted-foreground"
                                >&mdash;</span
                            >
                        </TableCell>
                        <TableCell>{{ site.permissions_count ?? 0 }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <!-- Pagination -->
            <div
                v-if="sites.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ sites.data.length }} of
                    {{ sites.total }} sites
                </p>
                <div class="flex gap-1">
                    <template v-for="link in sites.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                link.active
                                    ? 'bg-primary font-medium text-primary-foreground'
                                    : 'border hover:bg-muted',
                            ]"
                            ><!-- eslint-disable-next-line vue/no-v-html --><span
                                v-html="link.label"
                        /></Link>
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
