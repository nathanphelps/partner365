# Sweep Page Styling Conformance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the three sensitivity-label sweep pages (`Config.vue`, `History.vue`, `HistoryDetail.vue`) to match the rest of the Partner365 app's shadcn-vue + semantic-token conventions, fix dark-mode rendering, and add visible form validation errors on Config.

**Architecture:** Pure frontend Vue rewrites. Each file is a single-file big-bang rewrite that keeps script logic, props, breadcrumbs, Wayfinder action imports, and Inertia routing identical, replacing only the `<template>` block and adjusting imports. No controller, service, request, route, or migration changes. Backend validation already exists and is unit-tested; we just render its errors via the project's existing `<InputError>` and `<AlertError>` components.

**Tech Stack:** Vue 3 + `<script setup>`, Inertia.js v2 (`useForm`, `<Link>`, `<Head>`), Tailwind CSS v4, shadcn-vue components from `@/components/ui/*`, `lucide-vue-next` icons, Pest 4 backend tests.

**Spec:** `docs/superpowers/specs/2026-04-24-sweep-page-styling-design.md`

---

## File Structure

**Files modified (template + imports only; script logic preserved):**
- `resources/js/pages/sensitivity-labels/Sweep/Config.vue`
- `resources/js/pages/sensitivity-labels/Sweep/History.vue`
- `resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue`

**Files referenced for conventions (read-only):**
- `resources/js/pages/sensitivity-labels/Index.vue` — H1 + muted-subtitle pattern, empty-state Card, table+badge usage
- `resources/js/pages/admin/Syslog.vue` — `<InputError>` + `<Switch>` + `<Select>` patterns
- `resources/js/pages/admin/Sync.vue` — `<InputError>` under Inputs in form
- `resources/js/pages/partners/Create.vue` — long-form Card layout, Save button
- `resources/js/components/AlertError.vue` — top-of-page error-summary banner

**Existing tests that must keep passing:**
- `tests/Feature/Controllers/SensitivityLabelSweepConfigControllerTest.php` — asserts Inertia component name `'sensitivity-labels/Sweep/Config'` and props (unchanged).
- `tests/Feature/Controllers/SensitivityLabelSweepHistoryControllerTest.php` — asserts component name `'sensitivity-labels/Sweep/History'` / `'…/HistoryDetail'` and props (unchanged).

---

## Task 1: Establish a clean baseline

**Files:** none modified.

Confirm the working tree is clean and all existing checks pass *before* changing anything. If any check fails on the baseline, fix that first (or stop and report) — otherwise we won't be able to tell whether a later failure was caused by our changes.

- [ ] **Step 1: Confirm git state is clean**

Run: `git status`
Expected: only untracked/changed files unrelated to sweep pages (e.g. `.claude/settings.local.json`). Confirm `resources/js/pages/sensitivity-labels/Sweep/*` is NOT in the working set.

- [ ] **Step 2: Run frontend type check**

Run: `npm run types:check`
Expected: PASS (no type errors).

- [ ] **Step 3: Run frontend lint**

Run: `npm run lint`
Expected: PASS (no lint errors).

- [ ] **Step 4: Run sweep controller tests**

Run: `php artisan test --compact --filter='SensitivityLabelSweepConfigControllerTest|SensitivityLabelSweepHistoryControllerTest'`
Expected: PASS (8 tests across both files: 7 in Config, 1+ in History).

- [ ] **Step 5: Run frontend production build**

Run: `npm run build`
Expected: PASS (Vite builds without errors and the manifest is regenerated).

- [ ] **Step 6: Note the baseline**

If all of steps 2–5 passed, baseline is clean — proceed to Task 2. If any failed, those failures are pre-existing and must be reported to the user before starting; do not proceed.

---

## Task 2: Rewrite `Config.vue`

**Files:**
- Modify: `resources/js/pages/sensitivity-labels/Sweep/Config.vue`

This rewrite preserves the existing script logic (props, useForm, computed, addRule/removeRule/addExclusion/removeExclusion/submit, breadcrumbs) and replaces the entire `<template>` block. It also adds new component imports and a `<style>` is not needed (semantic tokens handle theming).

- [ ] **Step 1: Read the current file end-to-end**

Read: `resources/js/pages/sensitivity-labels/Sweep/Config.vue`
Confirm the script logic matches what's mapped below before editing. The file is ~350 lines; lines 1–111 are `<script setup>`, lines 113–350 are `<template>`.

- [ ] **Step 2: Replace the entire file with the new content**

Overwrite `resources/js/pages/sensitivity-labels/Sweep/Config.vue` with exactly:

```vue
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
                            <Switch
                                id="sweep-enabled"
                                v-model="form.enabled"
                            />
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
                            :class="
                                errorClass(!!form.errors.default_label_id)
                            "
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
                            <TableRow
                                v-for="(rule, i) in form.rules"
                                :key="i"
                            >
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
                                            form.errors[
                                                `rules.${i}.priority`
                                            ]
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
                                            form.errors[
                                                `rules.${i}.label_id`
                                            ]
                                        "
                                    />
                                </TableCell>
                                <TableCell class="align-top text-right">
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
                                <TableCell class="align-top text-right">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="
                                            `Remove exclusion ${i + 1}`
                                        "
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
```

- [ ] **Step 3: Run lint + format**

Run: `npm run lint && npm run format`
Expected: PASS. The `format` script runs `prettier --write resources/`, so any whitespace differences are auto-fixed. If `git diff` after this step shows reformatting beyond the file just changed, that's pre-existing drift — leave it for the user.

- [ ] **Step 4: Run TypeScript type check**

Run: `npm run types:check`
Expected: PASS. The keyed-error access pattern (`form.errors[\`rules.${i}.prefix\`]`) is valid Inertia v2 typing — `useForm` errors are `Partial<Record<string, string>>`.

- [ ] **Step 5: Run the Config controller tests**

Run: `php artisan test --compact --filter=SensitivityLabelSweepConfigControllerTest`
Expected: PASS (7 tests). The Inertia component name `'sensitivity-labels/Sweep/Config'` and exposed props are unchanged, so all assertions still hold.

- [ ] **Step 6: Run the production build to confirm no missing imports**

Run: `npm run build`
Expected: PASS. Vite resolves all new imports (`Card`, `Switch`, `Select`, `Alert`, `Trash2`, `Plus`, `AlertError`, `InputError`).

- [ ] **Step 7: Manual UI verification (admin user)**

Start dev: `composer run dev` (in a separate terminal). Log in as an admin and visit `/sensitivity-labels/sweep/config`. Confirm:

  1. Page header shows "Sensitivity sweep configuration" with a muted subtitle and a "View run history" outline button to the right.
  2. Five Cards render in order: Sweep status, Default label, Prefix rules, Site exclusions, Bridge connection.
  3. Bridge status badge is green/default when bridge is healthy, destructive (red) when `bridgeError` is set, outline when unknown.
  4. Toggling the OS theme to dark mode shows correct contrast (no white-on-white cards, no gray-on-gray text).
  5. "Save configuration" button is the default primary `<Button>` (not green).
  6. Clicking "Add rule" appends a new row with empty inputs; clicking the trash button removes a rule.
  7. **Validation flow:** Submit with `interval_minutes: 0` and a rule with empty prefix. Confirm:
      - Top-of-page `<AlertError>` banner appears.
      - `<InputError>` text appears under the Interval input AND under the offending rule's Prefix input.
      - Both inputs gain a destructive-colored border.
  8. **Save flow:** Submit valid configuration. Confirm form persists (reload to verify) and no errors appear.

If any of (1)–(8) fails, fix and re-verify before committing. If the dev server cannot be started, report that explicitly to the user instead of proceeding.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Sweep/Config.vue
git commit -m "$(cat <<'EOF'
style(sweep): conform Config.vue to app shadcn-vue + token conventions

Replace raw HTML primitives with shadcn-vue components (Card, Input,
Select, Switch, Button, Badge, Alert, Table). Switch hardcoded color
literals to semantic tokens so dark mode renders correctly. Surface
validation errors via <InputError> per field and a top-of-page
<AlertError> summary, plus border-destructive on offending inputs.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Rewrite `History.vue`

**Files:**
- Modify: `resources/js/pages/sensitivity-labels/Sweep/History.vue`

Read-only list page. Swap raw `<table>` for `<Table>`, raw status pill for `<Badge>`, "Configuration" link for outlined `<Button>`, and add an empty-state `<Card>`.

- [ ] **Step 1: Read the current file**

Read: `resources/js/pages/sensitivity-labels/Sweep/History.vue`
Confirm: 127 lines, props `runs: Pagination`, helpers `statusBadge()` and `duration()`.

- [ ] **Step 2: Replace the entire file with the new content**

Overwrite `resources/js/pages/sensitivity-labels/Sweep/History.vue` with exactly:

```vue
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
```

- [ ] **Step 3: Run lint + format + types check**

Run: `npm run lint && npm run format && npm run types:check`
Expected: PASS.

- [ ] **Step 4: Run the History controller tests**

Run: `php artisan test --compact --filter=SensitivityLabelSweepHistoryControllerTest`
Expected: PASS. Component name `'sensitivity-labels/Sweep/History'` and props unchanged.

- [ ] **Step 5: Run the production build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 6: Manual UI verification**

Start (or keep running) `composer run dev`. Visit `/sensitivity-labels/sweep/history`. Confirm:

  1. Header has H1 + muted subtitle + outline "Configuration" button on the right.
  2. If runs exist: table renders with semantic tokens, hover state on rows is the standard `bg-muted/50` (not blue), status column is a `<Badge>` with the right variant per status.
  3. If runs do not exist: a `<Card>` shows "No sweep runs yet." centered with muted text.
  4. Numeric columns (Scanned, Applied, Failed) right-aligned with tabular-nums.
  5. Dark mode renders correctly.

To force the empty state when seed data exists, run: `php artisan tinker --execute='\App\Models\SweepRun::query()->delete();'` and reload. Re-seed afterwards if needed.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Sweep/History.vue
git commit -m "$(cat <<'EOF'
style(sweep): conform History.vue to app shadcn-vue + token conventions

Swap raw <table> for <Table>, hand-rolled status pill for <Badge>, and
the right-aligned text link for an outline <Button>. Add a <Card>-based
empty state matching sensitivity-labels/Index.vue.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Rewrite `HistoryDetail.vue`

**Files:**
- Modify: `resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue`

Read-only detail page. Mirror History.vue's patterns. Wrap the run-summary grid in a `<Card>`. Swap action pill for `<Badge>`. Convert error text to `<Alert variant="destructive">`.

- [ ] **Step 1: Read the current file**

Read: `resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue`
Confirm: 154 lines, props `{ run: Run; entries: Entry[] }`, helper `actionClass()`.

- [ ] **Step 2: Replace the entire file with the new content**

Overwrite `resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue` with exactly:

```vue
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
```

- [ ] **Step 3: Run lint + format + types check**

Run: `npm run lint && npm run format && npm run types:check`
Expected: PASS.

- [ ] **Step 4: Run the History controller tests**

Run: `php artisan test --compact --filter=SensitivityLabelSweepHistoryControllerTest`
Expected: PASS.

- [ ] **Step 5: Run the production build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 6: Manual UI verification**

Visit `/sensitivity-labels/sweep/history/<id>` for any existing run. Confirm:

  1. H1 "Sweep run #N" with an outline "Back to history" button on the right.
  2. Run summary card shows three columns (Started / Status / Applied/Scanned). When `run.error_message` is non-null, an `<Alert variant="destructive">` appears below the grid.
  3. Entries table renders with action `<Badge>` variants: `default` for applied, `destructive` for failed, `secondary` for skipped_*, `outline` otherwise.
  4. URL column shows external links with `text-primary` and underline-on-hover.
  5. Empty state: if no entries, a `<Card>` shows "No entries in this run." centered with muted text. (To force this, pick a run id with no entries — check via `php artisan tinker --execute='\App\Models\SweepRunEntry::query()->groupBy("sweep_run_id")->select("sweep_run_id")->get();'`.)
  6. Dark mode renders correctly.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Sweep/HistoryDetail.vue
git commit -m "$(cat <<'EOF'
style(sweep): conform HistoryDetail.vue to app shadcn-vue + token conventions

Wrap run-summary grid in <Card>, replace action pill with <Badge>, and
move run-level error from raw red text into <Alert variant="destructive">.
Swap raw <table> for <Table> and hardcoded colors for semantic tokens.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Final full-suite verification

**Files:** none modified.

Run the full project checks once across all three rewrites to catch any cross-file regressions.

- [ ] **Step 1: Full type check**

Run: `npm run types:check`
Expected: PASS.

- [ ] **Step 2: Full lint**

Run: `npm run lint`
Expected: PASS.

- [ ] **Step 3: Format check**

Run: `npx prettier --check resources/`
Expected: PASS. If failures, run `npm run format` and re-commit any formatting fixes as `style: prettier on sweep pages`.

- [ ] **Step 4: PHP Pint sanity check**

Run: `vendor/bin/pint --dirty --format agent`
Expected: PASS or "No files needed formatting" — no PHP files were modified, so this should be a no-op.

- [ ] **Step 5: Full Pest suite**

Run: `composer run test`
Expected: PASS.

- [ ] **Step 6: Final production build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 7: Side-by-side visual comparison**

With dev server running, open both `/sensitivity-labels` (the conventional reference page) and `/sensitivity-labels/sweep/config` in adjacent browser tabs. Toggle dark mode. Confirm the visual language (card surfaces, table styling, badges, button shapes, header typography, spacing) is indistinguishable between the two. Repeat for `/sensitivity-labels/sweep/history` and `/sensitivity-labels/sweep/history/<id>`.

- [ ] **Step 8: Confirm git log shows three focused commits**

Run: `git log --oneline -n 5`
Expected: the three style(sweep) commits from Tasks 2–4 at the top, plus the docs commit for the spec from earlier.

If the repo is on a feature branch and the user wants to open a PR, that step is out of scope for this plan — hand off and let the user direct.

---

## Out of scope (deferred for separate work)

- Restructuring Config.vue's five sections as `<Tabs>`.
- "Saved" toast notification on Config submit success.
- Updating the project-wide `InputError.vue` component to use `text-destructive` instead of `text-red-600` (touches every form in the app).

## Notes for the implementer

- **Switch convention.** Two patterns coexist in the codebase: `:checked` / `@update:checked` (admin pages) and `:model-value` / `@update:model-value` (partners/Create.vue). This plan uses `v-model="form.enabled"` directly, which shadcn-vue's `<Switch>` supports as sugar for `:model-value` + `@update:model-value`. If `v-model` produces a runtime warning, fall back to `:checked` / `@update:checked`.
- **Inertia keyed errors.** `useForm`'s `errors` is `Partial<Record<string, string>>`. Nested array errors are exposed as dotted keys (`'rules.0.prefix'`). Bracket-access works without type errors because the index signature accepts any string.
- **Wayfinder URL helpers.** `SweepConfigController.show.url()`, `SweepConfigController.update.url()`, `SweepHistoryController.index.url()`, and `SweepHistoryController.show.url(id)` are all unchanged from the originals; do not reinvent them.
- **No backend changes.** If a step ever tells you to touch a controller, request, model, or migration, stop — the plan is wrong and the user should be consulted.
