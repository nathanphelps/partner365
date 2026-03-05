<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
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
import type { SensitivityLabel } from '@/types/sensitivity-label';

const props = defineProps<{
    label: SensitivityLabel;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
    {
        title: props.label.name,
        href: `/sensitivity-labels/${props.label.id}`,
    },
];

function protectionLabel(type: string): string {
    const map: Record<string, string> = {
        encryption: 'Encryption',
        watermark: 'Watermark',
        header_footer: 'Header/Footer',
        none: 'No protection',
    };
    return map[type] ?? type;
}

function protectionVariant(
    type: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        encryption: 'default',
        watermark: 'secondary',
        header_footer: 'secondary',
        none: 'outline',
    };
    return map[type] ?? 'outline';
}

function formatScope(scope: string[] | null): string {
    if (!scope || scope.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        files_emails: 'Files & Emails',
        sites_groups: 'Sites & Groups',
    };
    return scope.map((s) => labels[s] ?? s).join(', ');
}

function matchedViaLabel(via: string): string {
    return via === 'label_policy' ? 'Label Policy' : 'Site Assignment';
}
</script>

<template>
    <Head :title="label.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <div class="flex items-center gap-3">
                    <span
                        v-if="label.color"
                        class="inline-block size-4 rounded-full"
                        :style="{ backgroundColor: label.color }"
                    />
                    <h1 class="text-2xl font-semibold">
                        {{ label.name }}
                    </h1>
                    <Badge :variant="label.is_active ? 'default' : 'outline'">
                        {{ label.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>
                <p
                    v-if="label.description"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ label.description }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Label ID: {{ label.label_id }}
                </p>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Label Details</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Protection</span>
                    <span>
                        <Badge
                            :variant="protectionVariant(label.protection_type)"
                        >
                            {{ protectionLabel(label.protection_type) }}
                        </Badge>
                    </span>

                    <span class="text-muted-foreground">Scope</span>
                    <span>{{ formatScope(label.scope) }}</span>

                    <span class="text-muted-foreground">Priority</span>
                    <span>{{ label.priority }}</span>

                    <span v-if="label.tooltip" class="text-muted-foreground"
                        >Tooltip</span
                    >
                    <span v-if="label.tooltip">{{ label.tooltip }}</span>
                </CardContent>
            </Card>

            <Card v-if="label.children?.length">
                <CardHeader>
                    <CardTitle
                        >Sub-Labels ({{ label.children.length }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Protection</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="child in label.children"
                                :key="child.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/sensitivity-labels/${child.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ child.name }}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            protectionVariant(
                                                child.protection_type,
                                            )
                                        "
                                    >
                                        {{
                                            protectionLabel(
                                                child.protection_type,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            child.is_active
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            child.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Affected Partners ({{
                            label.partners?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="label.partners?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Partner</TableHead>
                                <TableHead>Domain</TableHead>
                                <TableHead>Matched Via</TableHead>
                                <TableHead>Source</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="partner in label.partners"
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
                                        {{
                                            matchedViaLabel(
                                                partner.pivot.matched_via,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-muted-foreground">
                                    {{
                                        partner.pivot.policy_name ??
                                        partner.pivot.site_name ??
                                        '\u2014'
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No partners matched by this label.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
