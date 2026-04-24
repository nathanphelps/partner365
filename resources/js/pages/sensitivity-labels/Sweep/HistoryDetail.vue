<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

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

defineProps<{ run: Run; entries: Entry[] }>();

function actionClass(action: string): string {
    if (action === 'applied') return 'bg-green-100 text-green-800';
    if (action === 'failed') return 'bg-red-100 text-red-800';
    if (action.startsWith('skipped')) return 'bg-yellow-100 text-yellow-800';
    return 'bg-gray-100 text-gray-800';
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-4 p-6">
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Sweep run #{{ run.id }}</h1>
            <Link
                href="/sensitivity-labels/sweep/history"
                class="text-sm text-blue-600 hover:underline"
            >
                Back to history
            </Link>
        </header>

        <section class="rounded-lg border bg-white p-4 text-sm shadow-sm">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <span class="font-medium">Started:</span>
                    {{ new Date(run.started_at).toLocaleString() }}
                </div>
                <div>
                    <span class="font-medium">Status:</span> {{ run.status }}
                </div>
                <div>
                    <span class="font-medium">Applied/Scanned:</span>
                    {{ run.applied }}/{{ run.total_scanned }}
                </div>
            </div>
            <div v-if="run.error_message" class="mt-2 text-red-700">
                {{ run.error_message }}
            </div>
        </section>

        <table class="w-full rounded-lg border bg-white text-sm shadow-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="px-3 py-2">Site</th>
                    <th class="px-3 py-2">URL</th>
                    <th class="px-3 py-2">Action</th>
                    <th class="px-3 py-2">Label</th>
                    <th class="px-3 py-2">Matched rule</th>
                    <th class="px-3 py-2">Error</th>
                    <th class="px-3 py-2">Time</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries" :key="entry.id" class="border-b">
                    <td class="px-3 py-2">{{ entry.site_title }}</td>
                    <td class="truncate px-3 py-2" :title="entry.site_url">
                        <a
                            :href="entry.site_url"
                            target="_blank"
                            rel="noopener"
                            class="text-blue-600 hover:underline"
                        >
                            {{ entry.site_url }}
                        </a>
                    </td>
                    <td class="px-3 py-2">
                        <span
                            class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                            :class="actionClass(entry.action)"
                        >
                            {{ entry.action }}
                        </span>
                    </td>
                    <td class="px-3 py-2">{{ entry.label_id ?? '—' }}</td>
                    <td class="px-3 py-2">
                        {{ entry.matched_rule?.prefix ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-red-700">
                        {{ entry.error_message ?? '' }}
                    </td>
                    <td class="px-3 py-2 text-gray-500">
                        {{ new Date(entry.processed_at).toLocaleString() }}
                    </td>
                </tr>
                <tr v-if="entries.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-gray-500">
                        No entries in this run.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
