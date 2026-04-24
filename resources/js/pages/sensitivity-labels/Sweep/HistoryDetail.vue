<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import * as SweepHistoryController from '@/actions/App/Http/Controllers/SensitivityLabelSweepHistoryController';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

interface Rule {
    id: number;
    prefix: string;
    label_id: string;
}
interface Entry {
    id: number;
    site_url: string;
    site_title: string;
    action: string;
    label_id: string | null;
    matched_rule: Rule | null;
    error_message: string | null;
    error_code: string | null;
    processed_at: string;
}

interface Run {
    id: number;
    started_at: string;
    completed_at: string | null;
    status: string;
    error_message: string | null;
    total_scanned: number;
    applied: number;
    failed: number;
    skipped_excluded: number;
    already_labeled: number;
}

const props = defineProps<{ run: Run; entries: Entry[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
    { title: 'Sweep History', href: SweepHistoryController.index.url() },
    {
        title: `Run #${props.run.id}`,
        href: SweepHistoryController.show.url(props.run.id),
    },
];

function actionVariant(
    action: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    if (action === 'applied') return 'default';
    if (action === 'failed') return 'destructive';
    if (action.startsWith('skipped')) return 'secondary';
    return 'outline';
}
</script>

<template>
    <Head :title="`Sweep Run #${run.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between gap-4">
                <h1 class="text-2xl font-semibold">Sweep run #{{ run.id }}</h1>
                <Button variant="outline" as-child>
                    <Link :href="SweepHistoryController.index.url()">
                        Back to history
                    </Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Run summary</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3 text-sm">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <span class="font-medium">Started:</span>
                            {{ new Date(run.started_at).toLocaleString() }}
                        </div>
                        <div>
                            <span class="font-medium">Status:</span>
                            {{ run.status }}
                        </div>
                        <div>
                            <span class="font-medium">Applied/Scanned:</span>
                            {{ run.applied }}/{{ run.total_scanned }}
                        </div>
                    </div>
                    <Alert v-if="run.error_message" variant="destructive">
                        <AlertDescription>
                            {{ run.error_message }}
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>

            <Card v-if="entries.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No entries in this run.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Site</TableHead>
                        <TableHead>URL</TableHead>
                        <TableHead>Action</TableHead>
                        <TableHead>Label</TableHead>
                        <TableHead>Matched rule</TableHead>
                        <TableHead>Error</TableHead>
                        <TableHead>Time</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="entry in entries" :key="entry.id">
                        <TableCell>{{ entry.site_title }}</TableCell>
                        <TableCell
                            class="max-w-xs truncate"
                            :title="entry.site_url"
                        >
                            <a
                                :href="entry.site_url"
                                target="_blank"
                                rel="noopener"
                                class="text-primary hover:underline"
                            >
                                {{ entry.site_url }}
                            </a>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="actionVariant(entry.action)">
                                {{ entry.action }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ entry.label_id ?? '—' }}</TableCell>
                        <TableCell>
                            {{ entry.matched_rule?.prefix ?? '—' }}
                        </TableCell>
                        <TableCell class="text-destructive">
                            {{ entry.error_message ?? '' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ new Date(entry.processed_at).toLocaleString() }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
