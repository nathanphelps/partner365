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
import type { SharePointSite } from '@/types/sharepoint';

const props = defineProps<{
    site: SharePointSite;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'SharePoint Sites', href: '/sharepoint-sites' },
    {
        title: props.site.display_name,
        href: `/sharepoint-sites/${props.site.id}`,
    },
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

function formatBytes(bytes: number | null): string {
    if (bytes === null || bytes === undefined) return '\u2014';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let val = bytes;
    while (val >= 1024 && i < units.length - 1) {
        val /= 1024;
        i++;
    }
    return `${val.toFixed(1)} ${units[i]}`;
}

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleString();
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

function grantedViaLabel(via: string): string {
    const map: Record<string, string> = {
        direct: 'Direct',
        sharing_link: 'Sharing Link',
        group_membership: 'Group Membership',
        site_access: 'Site Access',
    };
    return map[via] ?? via;
}
</script>

<template>
    <Head :title="site.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ site.display_name }}
                        </h1>
                        <Badge
                            :variant="
                                sharingVariant(site.external_sharing_capability)
                            "
                        >
                            {{ sharingLabel(site.external_sharing_capability) }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ site.url }}
                    </p>
                </div>
                <a :href="site.url" target="_blank" rel="noopener noreferrer">
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        Open in SharePoint
                    </Button>
                </a>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Site Details</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Description</span>
                    <span>{{ site.description || '\u2014' }}</span>

                    <span class="text-muted-foreground">Sensitivity Label</span>
                    <span>
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
                        <template v-else>&mdash;</template>
                    </span>

                    <span class="text-muted-foreground">Owner</span>
                    <span>{{ site.owner_display_name || '\u2014' }}</span>

                    <span class="text-muted-foreground">Owner Email</span>
                    <span>{{ site.owner_email || '\u2014' }}</span>

                    <span class="text-muted-foreground">Storage Used</span>
                    <span>{{ formatBytes(site.storage_used_bytes) }}</span>

                    <span class="text-muted-foreground">Last Activity</span>
                    <span>{{ formatDate(site.last_activity_at) }}</span>

                    <span class="text-muted-foreground">Last Synced</span>
                    <span>{{ formatDate(site.synced_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Access Controls</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground"
                        >Conditional Access</span
                    >
                    <span>
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
                    </span>

                    <span class="text-muted-foreground">Allow Editing</span>
                    <span>{{ site.allow_editing ? 'Yes' : 'No' }}</span>

                    <span class="text-muted-foreground"
                        >Limited Access File Type</span
                    >
                    <span>{{ site.limited_access_file_type ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Allow Download</span>
                    <span>{{
                        site.allow_downloading_non_web_viewable ? 'Yes' : 'No'
                    }}</span>

                    <template
                        v-if="
                            site.sharing_domain_restriction_mode &&
                            site.sharing_domain_restriction_mode !== 'None'
                        "
                    >
                        <span class="text-muted-foreground"
                            >Domain Restriction Mode</span
                        >
                        <span>{{ site.sharing_domain_restriction_mode }}</span>

                        <template v-if="site.sharing_allowed_domain_list">
                            <span class="text-muted-foreground"
                                >Allowed Domains</span
                            >
                            <span>{{ site.sharing_allowed_domain_list }}</span>
                        </template>

                        <template v-if="site.sharing_blocked_domain_list">
                            <span class="text-muted-foreground"
                                >Blocked Domains</span
                            >
                            <span>{{ site.sharing_blocked_domain_list }}</span>
                        </template>
                    </template>

                    <template
                        v-if="site.external_user_expiration_days !== null"
                    >
                        <span class="text-muted-foreground"
                            >External User Expiration</span
                        >
                        <span
                            >{{ site.external_user_expiration_days }} days</span
                        >
                    </template>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Guest Access ({{
                            site.permissions?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="site.permissions?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Guest User</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Partner</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Granted Via</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="perm in site.permissions"
                                :key="perm.id"
                            >
                                <TableCell>
                                    <Link
                                        v-if="perm.guest_user"
                                        :href="`/guests/${perm.guest_user.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{
                                            perm.guest_user.display_name ??
                                            perm.guest_user.email
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground"
                                        >&mdash;</span
                                    >
                                </TableCell>
                                <TableCell>{{
                                    perm.guest_user?.email ?? '\u2014'
                                }}</TableCell>
                                <TableCell>
                                    <Link
                                        v-if="
                                            perm.guest_user
                                                ?.partner_organization
                                        "
                                        :href="`/partners/${perm.guest_user.partner_organization.id}`"
                                        class="hover:underline"
                                    >
                                        {{
                                            perm.guest_user.partner_organization
                                                .display_name
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground"
                                        >&mdash;</span
                                    >
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {{ perm.role }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        {{ grantedViaLabel(perm.granted_via) }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No guest users have access to this site.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
