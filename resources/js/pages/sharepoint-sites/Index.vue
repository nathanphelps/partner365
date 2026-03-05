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
        </div>
    </AppLayout>
</template>
