<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type SyncLogEntry = {
    id: string;
    type: string;
    status: string;
    records_synced: number | null;
    error_message: string | null;
    started_at: string;
    completed_at: string | null;
};

type Props = {
    intervals: {
        partners_interval_minutes: string | number;
        guests_interval_minutes: string | number;
    };
    logs: {
        partners: SyncLogEntry[];
        guests: SyncLogEntry[];
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Sync Settings', href: '/admin/sync' },
];

const form = useForm({
    partners_interval_minutes: Number(
        props.intervals.partners_interval_minutes,
    ),
    guests_interval_minutes: Number(props.intervals.guests_interval_minutes),
});

const submit = () => {
    form.put('/admin/sync');
};

const syncing = ref<Record<string, boolean>>({
    partners: false,
    guests: false,
});

const triggerSync = async (type: 'partners' | 'guests') => {
    syncing.value[type] = true;
    try {
        await fetch(`/admin/sync/${type}/run`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
                Accept: 'application/json',
            },
        });
    } finally {
        syncing.value[type] = false;
    }
};

const statusVariant = (status: string) => {
    if (status === 'completed') return 'default' as const;
    if (status === 'failed') return 'destructive' as const;
    return 'secondary' as const;
};

const formatDuration = (start: string, end: string | null) => {
    if (!end) return '\u2014';
    const ms = new Date(end).getTime() - new Date(start).getTime();
    return `${(ms / 1000).toFixed(1)}s`;
};

const expandedError = ref<string | null>(null);
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="Sync Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Sync Settings"
                description="Configure automatic synchronization intervals"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="partners_interval"
                        >Partners sync interval (minutes)</Label
                    >
                    <Input
                        id="partners_interval"
                        v-model="form.partners_interval_minutes"
                        type="number"
                        min="1"
                        max="1440"
                    />
                    <InputError
                        :message="form.errors.partners_interval_minutes"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="guests_interval"
                        >Guests sync interval (minutes)</Label
                    >
                    <Input
                        id="guests_interval"
                        v-model="form.guests_interval_minutes"
                        type="number"
                        min="1"
                        max="1440"
                    />
                    <InputError
                        :message="form.errors.guests_interval_minutes"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="form.recentlySuccessful"
                            class="text-sm text-muted-foreground"
                        >
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>

            <template
                v-for="type in ['partners', 'guests'] as const"
                :key="type"
            >
                <div class="border-t pt-6">
                    <div class="flex items-center justify-between">
                        <Heading
                            variant="small"
                            :title="`${type.charAt(0).toUpperCase() + type.slice(1)} Sync`"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="syncing[type]"
                            @click="triggerSync(type)"
                        >
                            {{ syncing[type] ? 'Syncing...' : 'Sync Now' }}
                        </Button>
                    </div>

                    <Table v-if="logs[type].length" class="mt-4">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Status</TableHead>
                                <TableHead>Records</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead>Started</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template
                                v-for="entry in logs[type]"
                                :key="entry.id"
                            >
                                <TableRow>
                                    <TableCell>
                                        <Badge
                                            :variant="
                                                statusVariant(entry.status)
                                            "
                                            >{{ entry.status }}</Badge
                                        >
                                        <button
                                            v-if="entry.error_message"
                                            class="ml-2 text-xs text-red-600 underline"
                                            @click="
                                                expandedError =
                                                    expandedError === entry.id
                                                        ? null
                                                        : entry.id
                                            "
                                        >
                                            {{
                                                expandedError === entry.id
                                                    ? 'hide'
                                                    : 'details'
                                            }}
                                        </button>
                                    </TableCell>
                                    <TableCell>{{
                                        entry.records_synced ?? '\u2014'
                                    }}</TableCell>
                                    <TableCell>{{
                                        formatDuration(
                                            entry.started_at,
                                            entry.completed_at,
                                        )
                                    }}</TableCell>
                                    <TableCell>{{
                                        new Date(
                                            entry.started_at,
                                        ).toLocaleString()
                                    }}</TableCell>
                                </TableRow>
                                <TableRow v-if="expandedError === entry.id">
                                    <TableCell
                                        colspan="4"
                                        class="bg-red-50 text-sm text-red-700"
                                    >
                                        {{ entry.error_message }}
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                    <p v-else class="mt-4 text-sm text-muted-foreground">
                        No sync history yet.
                    </p>
                </div>
            </template>
        </div>
    </AdminLayout>
</template>
