<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

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

function statusBadge(status: string): string {
    if (status === 'success') return 'bg-green-100 text-green-800';
    if (status === 'partial_failure') return 'bg-yellow-100 text-yellow-800';
    if (status === 'failed' || status === 'aborted')
        return 'bg-red-100 text-red-800';
    return 'bg-gray-100 text-gray-800';
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
    <div class="mx-auto max-w-5xl space-y-4 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Sweep run history</h1>
            <Link
                href="/sensitivity-labels/sweep/config"
                class="text-sm text-blue-600 hover:underline"
            >
                Configuration
            </Link>
        </header>

        <table class="w-full rounded-lg border bg-white text-sm shadow-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="px-3 py-2">#</th>
                    <th class="px-3 py-2">Started</th>
                    <th class="px-3 py-2">Duration</th>
                    <th class="px-3 py-2 text-right">Scanned</th>
                    <th class="px-3 py-2 text-right">Applied</th>
                    <th class="px-3 py-2 text-right">Failed</th>
                    <th class="px-3 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="run in runs.data"
                    :key="run.id"
                    class="border-b hover:bg-blue-50"
                >
                    <td class="px-3 py-2">
                        <Link
                            :href="`/sensitivity-labels/sweep/history/${run.id}`"
                            class="text-blue-600 hover:underline"
                        >
                            #{{ run.id }}
                        </Link>
                    </td>
                    <td class="px-3 py-2">
                        {{ new Date(run.started_at).toLocaleString() }}
                    </td>
                    <td class="px-3 py-2">{{ duration(run) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ run.total_scanned }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ run.applied }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ run.failed }}
                    </td>
                    <td class="px-3 py-2">
                        <span
                            class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                            :class="statusBadge(run.status)"
                        >
                            {{ run.status }}
                        </span>
                    </td>
                </tr>
                <tr v-if="runs.data.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-gray-500">
                        No sweep runs yet.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
