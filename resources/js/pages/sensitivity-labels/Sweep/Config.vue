<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import * as SweepConfigController from '@/actions/App/Http/Controllers/SensitivityLabelSweepConfigController';
import * as SweepHistoryController from '@/actions/App/Http/Controllers/SensitivityLabelSweepHistoryController';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

interface Label {
    id: number;
    label_id: string;
    name: string;
}
interface Rule {
    prefix: string;
    label_id: string;
    priority: number;
}
interface Exclusion {
    pattern: string;
}
interface BridgeHealth {
    status: string;
    cloudEnvironment: string;
    certThumbprint: string;
}
interface LastRun {
    id: number;
    started_at: string;
    status: string;
    applied: number;
    total_scanned: number;
}

const props = defineProps<{
    settings: {
        enabled: boolean;
        interval_minutes: number;
        default_label_id: string;
        bridge_url: string;
        bridge_shared_secret_configured: boolean;
    };
    rules: Rule[];
    exclusions: Exclusion[];
    labels: Label[];
    lastRun: LastRun | null;
    bridgeHealth: BridgeHealth | null;
    bridgeError: string | null;
}>();

// `bridge_shared_secret` is write-only in the form — leaving it blank means
// "don't rotate", which keeps the encrypted value in the database untouched.
const form = useForm({
    enabled: props.settings.enabled,
    interval_minutes: props.settings.interval_minutes,
    default_label_id: props.settings.default_label_id,
    bridge_url: props.settings.bridge_url,
    bridge_shared_secret: '',
    rules: props.rules.map((r) => ({
        prefix: r.prefix,
        label_id: r.label_id,
        priority: r.priority,
    })),
    exclusions: props.exclusions.map((e) => ({ pattern: e.pattern })),
});

const bridgeStatusLabel = computed(() => {
    if (props.bridgeError) return 'Unreachable';
    if (props.bridgeHealth)
        return `OK (${props.bridgeHealth.cloudEnvironment})`;
    return 'Unknown';
});

const bridgeStatusClass = computed(() => {
    if (props.bridgeError) return 'bg-red-100 text-red-800';
    if (props.bridgeHealth) return 'bg-green-100 text-green-800';
    return 'bg-gray-100 text-gray-800';
});

function addRule() {
    const maxPriority = form.rules.reduce((m, r) => Math.max(m, r.priority), 0);
    form.rules.push({
        prefix: '',
        label_id: props.labels[0]?.label_id ?? '',
        priority: maxPriority + 1,
    });
}

function removeRule(index: number) {
    form.rules.splice(index, 1);
}

function addExclusion() {
    form.exclusions.push({ pattern: '' });
}

function removeExclusion(index: number) {
    form.exclusions.splice(index, 1);
}

function submit() {
    form.put(SweepConfigController.update.url());
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
    { title: 'Sweep Configuration', href: SweepConfigController.show.url() },
];
</script>

<template>
    <Head title="Sweep Configuration" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <header class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">
                    Sensitivity sweep configuration
                </h1>
                <Link
                    :href="SweepHistoryController.index.url()"
                    class="text-sm text-blue-600 hover:underline"
                >
                    View run history
                </Link>
            </header>

            <section class="rounded-lg border bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Sweep status</h2>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                        :class="bridgeStatusClass"
                        :title="
                            bridgeHealth?.certThumbprint ?? bridgeError ?? ''
                        "
                    >
                        Bridge: {{ bridgeStatusLabel }}
                    </span>
                    <span v-if="lastRun" class="text-sm text-gray-500">
                        Last run #{{ lastRun.id }} — {{ lastRun.status }},
                        {{ lastRun.applied }}/{{ lastRun.total_scanned }}
                        applied
                    </span>
                </div>

                <div
                    v-if="bridgeError"
                    class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
                >
                    {{ bridgeError }}
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <label class="flex items-center gap-2">
                        <input v-model="form.enabled" type="checkbox" />
                        <span>Enabled</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="w-48">Interval (minutes)</span>
                        <input
                            v-model.number="form.interval_minutes"
                            type="number"
                            min="1"
                            class="w-24 rounded border px-2 py-1"
                        />
                    </label>
                </div>
            </section>

            <section class="rounded-lg border bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Default label</h2>
                <select
                    v-model="form.default_label_id"
                    class="w-full rounded border px-2 py-1"
                >
                    <option value="">(none)</option>
                    <option v-for="l in labels" :key="l.id" :value="l.label_id">
                        {{ l.name }}
                    </option>
                </select>
                <p class="mt-2 text-sm text-gray-500">
                    Applied when no prefix rule matches.
                </p>
            </section>

            <section class="rounded-lg border bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Prefix rules</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="w-16 px-2 py-1">Priority</th>
                            <th class="px-2 py-1">Prefix</th>
                            <th class="px-2 py-1">Label</th>
                            <th class="w-16 px-2 py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(rule, i) in form.rules"
                            :key="i"
                            class="border-b"
                        >
                            <td class="px-2 py-1">
                                <input
                                    v-model.number="rule.priority"
                                    type="number"
                                    min="1"
                                    class="w-16 rounded border px-2 py-1"
                                />
                            </td>
                            <td class="px-2 py-1">
                                <input
                                    v-model="rule.prefix"
                                    type="text"
                                    class="w-full rounded border px-2 py-1"
                                />
                            </td>
                            <td class="px-2 py-1">
                                <select
                                    v-model="rule.label_id"
                                    class="w-full rounded border px-2 py-1"
                                >
                                    <option
                                        v-for="l in labels"
                                        :key="l.id"
                                        :value="l.label_id"
                                    >
                                        {{ l.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-2 py-1">
                                <button
                                    type="button"
                                    @click="removeRule(i)"
                                    class="text-red-600 hover:underline"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button
                    type="button"
                    @click="addRule"
                    class="mt-2 rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700"
                >
                    Add rule
                </button>
            </section>

            <section class="rounded-lg border bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Site exclusions</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="px-2 py-1">Pattern</th>
                            <th class="w-16 px-2 py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(ex, i) in form.exclusions"
                            :key="i"
                            class="border-b"
                        >
                            <td class="px-2 py-1">
                                <input
                                    v-model="ex.pattern"
                                    type="text"
                                    class="w-full rounded border px-2 py-1"
                                />
                            </td>
                            <td class="px-2 py-1">
                                <button
                                    type="button"
                                    @click="removeExclusion(i)"
                                    class="text-red-600 hover:underline"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button
                    type="button"
                    @click="addExclusion"
                    class="mt-2 rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700"
                >
                    Add exclusion
                </button>
                <p class="mt-2 text-xs text-gray-500">
                    Case-insensitive substring match. Matching sites are dropped
                    from tracking on the next sweep.
                </p>
            </section>

            <section class="rounded-lg border bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Bridge connection</h2>
                <div class="grid grid-cols-1 gap-4">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">Bridge URL</span>
                        <input
                            v-model="form.bridge_url"
                            type="text"
                            class="rounded border px-2 py-1"
                        />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">
                            Shared secret
                            <span
                                v-if="settings.bridge_shared_secret_configured"
                                class="ml-2 text-xs text-green-700"
                            >
                                (currently configured — leave blank to keep)
                            </span>
                            <span v-else class="ml-2 text-xs text-red-700">
                                (not configured)
                            </span>
                        </span>
                        <input
                            v-model="form.bridge_shared_secret"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Enter a new secret to rotate"
                            class="rounded border px-2 py-1"
                        />
                    </label>
                </div>
            </section>

            <footer class="flex justify-end">
                <button
                    type="button"
                    @click="submit"
                    :disabled="form.processing"
                    class="rounded bg-green-600 px-4 py-2 text-white hover:bg-green-700 disabled:opacity-50"
                >
                    Save configuration
                </button>
            </footer>
        </div>
    </AppLayout>
</template>
