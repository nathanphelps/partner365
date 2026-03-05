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
import type { SensitivityLabel } from '@/types/sensitivity-label';

defineProps<{
    labels: Paginated<SensitivityLabel>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
];

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

function protectionLabel(type: string): string {
    const map: Record<string, string> = {
        encryption: 'Encryption',
        watermark: 'Watermark',
        header_footer: 'Header/Footer',
        none: 'No protection',
    };
    return map[type] ?? type;
}

function formatScope(scope: string[] | null): string {
    if (!scope || scope.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        files_emails: 'Files & Emails',
        sites_groups: 'Sites & Groups',
    };
    return scope.map((s) => labels[s] ?? s).join(', ');
}
</script>

<template>
    <Head title="Sensitivity Labels" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">Sensitivity Labels</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Microsoft Information Protection sensitivity labels and
                    their impact on partner organizations.
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
                    no sensitivity label coverage.
                </p>
            </div>

            <Card v-if="labels.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No sensitivity labels found. Run the sync command or wait
                    for the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Label</TableHead>
                        <TableHead>Protection</TableHead>
                        <TableHead>Scope</TableHead>
                        <TableHead>Priority</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Affected Partners</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="label in labels.data" :key="label.id">
                        <TableCell>
                            <Link
                                :href="`/sensitivity-labels/${label.id}`"
                                class="font-medium hover:underline"
                            >
                                <span
                                    v-if="label.color"
                                    class="mr-2 inline-block size-3 rounded-full"
                                    :style="{ backgroundColor: label.color }"
                                />
                                {{ label.name }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    protectionVariant(label.protection_type)
                                "
                            >
                                {{ protectionLabel(label.protection_type) }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ formatScope(label.scope) }}</TableCell>
                        <TableCell>{{ label.priority }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    label.is_active ? 'default' : 'outline'
                                "
                            >
                                {{ label.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ label.partners_count ?? 0 }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
