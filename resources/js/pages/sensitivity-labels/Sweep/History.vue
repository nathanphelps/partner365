<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import * as SweepConfigController from '@/actions/App/Http/Controllers/SensitivityLabelSweepConfigController';
import * as SweepHistoryController from '@/actions/App/Http/Controllers/SensitivityLabelSweepHistoryController';
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

interface Run {
    id: number;
    started_at: string;
    completed_at: string | null;
    total_scanned: number;
    applied: number;
    failed: number;
    status: string;
}

interface Pagination {
    data: Run[];
    links: unknown[];
    meta?: unknown;
}

defineProps<{ runs: Pagination }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
    { title: 'Sweep History', href: SweepHistoryController.index.url() },
];

function statusVariant(
    status: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    if (status === 'success') return 'default';
    if (status === 'partial_failure') return 'secondary';
    if (status === 'failed' || status === 'aborted') return 'destructive';
    return 'outline';
}

function duration(run: Run): string {
    if (!run.completed_at) return '—';
    const started = new Date(run.started_at).getTime();
    const completed = new Date(run.completed_at).getTime();
    const seconds = Math.round((completed - started) / 1000);
    if (seconds < 60) return `${seconds}s`;
    return `${Math.round(seconds / 60)}m`;
}
</script>

<template>
    <Head title="Sweep Run History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">Sweep run history</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Recent sensitivity-label sweep runs and their outcomes.
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="SweepConfigController.show.url()">
                        Configuration
                    </Link>
                </Button>
            </div>

            <Card v-if="runs.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No sweep runs yet.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>#</TableHead>
                        <TableHead>Started</TableHead>
                        <TableHead>Duration</TableHead>
                        <TableHead class="text-right">Scanned</TableHead>
                        <TableHead class="text-right">Applied</TableHead>
                        <TableHead class="text-right">Failed</TableHead>
                        <TableHead>Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="run in runs.data" :key="run.id">
                        <TableCell>
                            <Link
                                :href="SweepHistoryController.show.url(run.id)"
                                class="font-medium hover:underline"
                            >
                                #{{ run.id }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            {{ new Date(run.started_at).toLocaleString() }}
                        </TableCell>
                        <TableCell>{{ duration(run) }}</TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ run.total_scanned }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ run.applied }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ run.failed }}
                        </TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(run.status)">
                                {{ run.status }}
                            </Badge>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
