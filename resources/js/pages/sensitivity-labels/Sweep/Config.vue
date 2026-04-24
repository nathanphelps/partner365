<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import * as SweepConfigController from '@/actions/App/Http/Controllers/SensitivityLabelSweepConfigController';
import * as SweepHistoryController from '@/actions/App/Http/Controllers/SensitivityLabelSweepHistoryController';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
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

interface LabelOption {
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
    labels: LabelOption[];
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

const bridgeStatusVariant = computed<
    'default' | 'destructive' | 'outline' | 'secondary'
>(() => {
    if (props.bridgeError) return 'destructive';
    if (props.bridgeHealth) return 'default';
    return 'outline';
});

const formErrorMessages = computed(() =>
    Object.values(form.errors).filter(
        (v): v is string => typeof v === 'string' && v.length > 0,
    ),
);

const errorClass = (hasError: boolean) =>
    hasError ? 'border-destructive' : '';

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
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">
                        Sensitivity sweep configuration
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Tune how Partner365 applies sensitivity labels to
                        SharePoint sites and configure the on-prem bridge.
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="SweepHistoryController.index.url()">
                        View run history
                    </Link>
                </Button>
            </div>

            <AlertError
                v-if="formErrorMessages.length > 0"
                :errors="formErrorMessages"
                title="Please fix the issues below before saving."
            />

            <Card>
                <CardHeader>
                    <CardTitle>Sweep status</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="bridgeStatusVariant"
                            :title="
                                bridgeHealth?.certThumbprint ??
                                bridgeError ??
                                ''
                            "
                        >
                            Bridge: {{ bridgeStatusLabel }}
                        </Badge>
                        <span
                            v-if="lastRun"
                            class="text-sm text-muted-foreground"
                        >
                            Last run #{{ lastRun.id }} — {{ lastRun.status }},
                            {{ lastRun.applied }}/{{ lastRun.total_scanned }}
                            applied
                        </span>
                    </div>

                    <Alert v-if="bridgeError" variant="destructive">
                        <AlertDescription>{{ bridgeError }}</AlertDescription>
                    </Alert>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex items-center gap-3">
                            <Switch id="sweep-enabled" v-model="form.enabled" />
                            <Label for="sweep-enabled">Enabled</Label>
                        </div>
                        <div class="grid gap-2">
                            <Label for="sweep-interval">
                                Interval (minutes)
                            </Label>
                            <Input
                                id="sweep-interval"
                                v-model.number="form.interval_minutes"
                                type="number"
                                min="1"
                                class="max-w-32"
                                :class="
                                    errorClass(!!form.errors.interval_minutes)
                                "
                            />
                            <InputError
                                :message="form.errors.interval_minutes"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Default label</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Select v-model="form.default_label_id">
                        <SelectTrigger
                            :class="errorClass(!!form.errors.default_label_id)"
                        >
                            <SelectValue placeholder="(none)" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="l in labels"
                                :key="l.id"
                                :value="l.label_id"
                            >
                                {{ l.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.default_label_id" />
                    <p class="text-sm text-muted-foreground">
                        Applied when no prefix rule matches.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Prefix rules</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-24">Priority</TableHead>
                                <TableHead>Prefix</TableHead>
                                <TableHead>Label</TableHead>
                                <TableHead class="w-16" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="(rule, i) in form.rules" :key="i">
                                <TableCell class="align-top">
                                    <Input
                                        v-model.number="rule.priority"
                                        type="number"
                                        min="1"
                                        class="w-20"
                                        :class="
                                            errorClass(
                                                !!form.errors[
                                                    `rules.${i}.priority`
                                                ],
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            form.errors[`rules.${i}.priority`]
                                        "
                                    />
                                </TableCell>
                                <TableCell class="align-top">
                                    <Input
                                        v-model="rule.prefix"
                                        type="text"
                                        :class="
                                            errorClass(
                                                !!form.errors[
                                                    `rules.${i}.prefix`
                                                ],
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            form.errors[`rules.${i}.prefix`]
                                        "
                                    />
                                </TableCell>
                                <TableCell class="align-top">
                                    <Select v-model="rule.label_id">
                                        <SelectTrigger
                                            :class="
                                                errorClass(
                                                    !!form.errors[
                                                        `rules.${i}.label_id`
                                                    ],
                                                )
                                            "
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="l in labels"
                                                :key="l.id"
                                                :value="l.label_id"
                                            >
                                                {{ l.name }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="
                                            form.errors[`rules.${i}.label_id`]
                                        "
                                    />
                                </TableCell>
                                <TableCell class="text-right align-top">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Remove rule ${i + 1}`"
                                        @click="removeRule(i)"
                                    >
                                        <Trash2
                                            class="size-4 text-destructive"
                                        />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="self-start"
                        @click="addRule"
                    >
                        <Plus class="mr-1 size-4" />
                        Add rule
                    </Button>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Site exclusions</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Pattern</TableHead>
                                <TableHead class="w-16" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="(ex, i) in form.exclusions"
                                :key="i"
                            >
                                <TableCell class="align-top">
                                    <Input
                                        v-model="ex.pattern"
                                        type="text"
                                        :class="
                                            errorClass(
                                                !!form.errors[
                                                    `exclusions.${i}.pattern`
                                                ],
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            form.errors[
                                                `exclusions.${i}.pattern`
                                            ]
                                        "
                                    />
                                </TableCell>
                                <TableCell class="text-right align-top">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Remove exclusion ${i + 1}`"
                                        @click="removeExclusion(i)"
                                    >
                                        <Trash2
                                            class="size-4 text-destructive"
                                        />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="self-start"
                        @click="addExclusion"
                    >
                        <Plus class="mr-1 size-4" />
                        Add exclusion
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        Case-insensitive substring match. Matching sites are
                        dropped from tracking on the next sweep.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Bridge connection</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="bridge-url">Bridge URL</Label>
                        <Input
                            id="bridge-url"
                            v-model="form.bridge_url"
                            type="text"
                            :class="errorClass(!!form.errors.bridge_url)"
                        />
                        <InputError :message="form.errors.bridge_url" />
                    </div>
                    <div class="grid gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <Label for="bridge-secret">Shared secret</Label>
                            <Badge
                                v-if="settings.bridge_shared_secret_configured"
                                variant="secondary"
                            >
                                Configured — leave blank to keep
                            </Badge>
                            <Badge v-else variant="destructive">
                                Not configured
                            </Badge>
                        </div>
                        <Input
                            id="bridge-secret"
                            v-model="form.bridge_shared_secret"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Enter a new secret to rotate"
                            :class="
                                errorClass(!!form.errors.bridge_shared_secret)
                            "
                        />
                        <InputError
                            :message="form.errors.bridge_shared_secret"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex justify-end">
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="submit"
                >
                    Save configuration
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
