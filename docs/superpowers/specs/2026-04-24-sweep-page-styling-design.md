# Sweep Page Styling Conformance — Design

**Date:** 2026-04-24
**Status:** Draft, awaiting user review
**Scope:** Restyle the three sensitivity-label sweep pages to match the rest of the Partner365 app's visual conventions. Pure cosmetic conformance plus visible validation rendering. No behavior, controller, or backend changes.

## Background

Three Vue pages under `resources/js/pages/sensitivity-labels/Sweep/` were authored before the project's shadcn-vue conventions were applied consistently:

- `Config.vue`
- `History.vue`
- `HistoryDetail.vue`

Compared with the rest of the app (e.g. `sensitivity-labels/Index.vue`, `partners/Create.vue`, `admin/Sync.vue`), these three files:

- Use raw HTML (`<input>`, `<select>`, `<button>`, `<table>`, `<section>`) instead of shadcn-vue components from `@/components/ui/*`.
- Hardcode color literals (`bg-white`, `text-blue-600`, `text-gray-500`, `bg-green-600`, `bg-red-50`, `text-red-700`, etc.) instead of semantic tokens (`bg-card`, `text-primary`, `text-muted-foreground`, `text-destructive`).
- Have no dark-mode support and render incorrectly in dark theme.
- Hand-roll status pills as `<span class="bg-green-100 text-green-800">…</span>` instead of `<Badge variant="…">`.
- Use a green save button instead of the project's default primary `<Button>`.
- Constrain content with `mx-auto max-w-*xl` instead of the app-standard full-width `flex flex-col gap-6 p-6` shell.
- Render no validation errors — submitting an invalid form fails silently from the user's perspective.

Every shadcn-vue component needed (`Card`, `Badge`, `Table`, `Button`, `Input`, `Select`, `Label`, `Switch`, `Alert`, `InputError`) already exists in `resources/js/components/`. This is pure conformance work.

## Goals

1. Make the three sweep pages visually indistinguishable in style from the rest of the app.
2. Make them work correctly in both light and dark mode.
3. Surface validation errors on Config submit — both inline per-field and as a top-of-page summary banner.

## Non-goals

- No controller, service, request, route, or migration changes.
- No new features (no toasts, no Tabs restructure, no inline save spinner unless trivial).
- No edits to the existing `InputError.vue` component, even though it uses `text-red-600` instead of `text-destructive` (changing it would ripple across every form in the app).
- No backend validation-rule changes.

## Shared conventions (apply to all three files)

**Page shell.** Replace `mx-auto max-w-*xl space-y-* p-6` with the app-standard `flex flex-col gap-6 p-6`.

**Page header.** Replace the "H1 + right-aligned bare `<Link>`" pattern with the Index.vue pattern: H1, muted subtitle paragraph, and any cross-page navigation as `<Button variant="outline" as-child><Link>…</Link></Button>` next to the H1.

**Cards.** Every `<section class="rounded-lg border bg-white p-* shadow-sm">` becomes:

```vue
<Card>
  <CardHeader><CardTitle>…</CardTitle></CardHeader>
  <CardContent>…</CardContent>
</Card>
```

**Form primitives.** Every raw `<input>` / `<select>` / `<button>` / `<label>` becomes the corresponding component from `@/components/ui/*` (`Input`, `Select` + `SelectTrigger`/`SelectContent`/`SelectItem`, `Button`, `Label`). Boolean toggles become `<Switch>`.

**Tables.** Every raw `<table>` / `<thead>` / `<tr>` / `<th>` / `<td>` becomes `<Table>` / `<TableHeader>` / `<TableRow>` / `<TableHead>` / `<TableCell>`.

**Status pills.** Every hand-rolled `<span class="bg-*-100 text-*-800">` becomes `<Badge variant="…">`. Variant mapping:

| State | Variant |
|---|---|
| `success`, `applied`, healthy/OK | `default` |
| `partial_failure`, `skipped_*`, neutral info | `secondary` |
| `failed`, `aborted`, `Unreachable` | `destructive` |
| `Unknown`, idle | `outline` |

**Color tokens.** No raw color literals.

| From | To |
|---|---|
| `text-gray-500` | `text-muted-foreground` |
| `text-blue-600 hover:underline` (Inertia link) | `<Link class="text-primary hover:underline">` |
| `text-blue-600 hover:underline` (button-like nav) | `<Button variant="outline" as-child>` |
| `text-red-*` (error body text) | `text-destructive` |
| `bg-red-50 border-red-300` (error banner) | `<Alert variant="destructive">` |
| `bg-white` (card surface) | dropped — `<Card>` handles it |
| `hover:bg-blue-50` (table rows) | dropped — `<TableRow>` provides `hover:bg-muted/50` |

**Dark mode.** Inherited automatically by switching to semantic tokens and shadcn components. No explicit `dark:` classes needed.

**Save button.** Green `<button class="bg-green-600">` becomes the default `<Button>` (primary variant), matching every other save action in the app.

## File 1: `Config.vue`

Five `<section>` blocks become five `<Card>` blocks, in the existing order.

**Page header.** H1 stays as "Sensitivity sweep configuration." Add a muted subtitle. The right-side "View run history" link becomes `<Button variant="outline" as-child>` linking to `SweepHistoryController.index.url()`.

**Sweep status card.**
- Bridge status pill becomes `<Badge>` with variant chosen by the existing logic (`destructive` if `bridgeError`, `default` if `bridgeHealth`, `outline` otherwise). Cert thumbprint stays as the `title` tooltip.
- "Last run #N — status, applied/scanned" stays inline as `text-sm text-muted-foreground`.
- Bridge error block becomes `<Alert variant="destructive">` with `<AlertDescription>{{ bridgeError }}</AlertDescription>`.
- "Enabled" checkbox becomes `<Switch>` + `<Label>`.
- "Interval (minutes)" becomes `<Label>` + `<Input type="number" min="1">` with constrained width.

**Default label card.**
- `<select>` becomes `<Select v-model="form.default_label_id">` with `<SelectTrigger>`, `<SelectContent>`, and one `<SelectItem>` per label. The "(none)" entry is a `<SelectItem value="">`.
- Caption becomes `text-sm text-muted-foreground`.

**Prefix rules card.**
- `<table>` family becomes `<Table>` family.
- Per-row inputs: `<Input type="number" min="1">` for priority, `<Input>` for prefix, `<Select>` for label.
- Remove button: `<Button variant="ghost" size="icon">` with the `Trash2` icon from `lucide-vue-next`.
- "Add rule" button: `<Button variant="outline" size="sm">` with the `Plus` icon.

**Site exclusions card.** Same shape as prefix rules with two columns (pattern + remove). Same button conventions. Caption becomes `text-xs text-muted-foreground`.

**Bridge connection card.**
- Bridge URL: `<Label>` + `<Input>`.
- Shared secret: `<Label>` (with the inline "configured / not configured" hint as `<Badge variant="secondary">` or `<Badge variant="destructive">` next to the label) + `<Input type="password" autocomplete="new-password">`.

**Footer.** The green save button becomes the default `<Button type="button" :disabled="form.processing">Save configuration</Button>`. The disabled state alone is sufficient for this conformance pass; no inline spinner is added.

### Validation rendering (Config.vue only)

Use the project's `<InputError :message="form.errors.<field>" />` component, matching the convention in `admin/Sync.vue`, `admin/Graph.vue`, `admin/Sso.vue`, `admin/Syslog.vue`, and `admin/Collaboration.vue`.

Field-level errors rendered directly under each field:

- `interval_minutes` — under the Interval input
- `default_label_id` — under the Default label select
- `bridge_url` — under the Bridge URL input
- `bridge_shared_secret` — under the Shared secret input
- For each rule row, indexed by `i`: `form.errors['rules.' + i + '.prefix']`, `form.errors['rules.' + i + '.label_id']`, `form.errors['rules.' + i + '.priority']` rendered under each input cell. Inertia v2's `useForm` exposes nested errors via dotted-key strings.
- For each exclusion row, indexed by `i`: `form.errors['exclusions.' + i + '.pattern']`.

Form-level error banner: at the top of the page, above the first card, render an `<Alert variant="destructive">` when `Object.keys(form.errors).length > 0`, with the body "Please fix the issues below before saving."

Inputs and selects with errors get `border-destructive` applied via `:class`, matching the pattern in `templates/Create.vue:78`.

The Laravel controller for `update` already returns standard Inertia validation errors via the redirect-with-errors flow; no backend change is needed.

## File 2: `History.vue`

- Page shell: drop the centered max-width container; use `flex flex-col gap-6 p-6`.
- Header: H1 "Sweep run history" stays. Add a muted subtitle: "Recent sensitivity-label sweep runs and their outcomes." The "Configuration" link becomes `<Button variant="outline" as-child><Link>Configuration</Link></Button>`.
- Table: `<Table>` family. Run-id column: `<Link class="font-medium hover:underline">` matching `Index.vue:135`.
- Status column: replace the `statusBadge()` class-string helper with a `statusVariant()` helper returning `'default' | 'secondary' | 'destructive' | 'outline'`. Render via `<Badge :variant="statusVariant(run.status)">{{ run.status }}</Badge>`.
- Row hover: drop `hover:bg-blue-50`; `<TableRow>` provides `hover:bg-muted/50` by default.
- Numeric columns: keep `text-right tabular-nums`.
- Empty state: replace the colspan row with a conditional `<Card>` matching `Index.vue:111-116`:

```vue
<Card v-if="runs.data.length === 0">
  <CardContent class="py-12 text-center text-muted-foreground">
    No sweep runs yet.
  </CardContent>
</Card>
<Table v-else>…</Table>
```

## File 3: `HistoryDetail.vue`

- Page shell: drop the centered max-width container; use `flex flex-col gap-6 p-6`.
- Header: H1 "Sweep run #N" stays. No subtitle (the run-id H1 plus breadcrumbs is enough context). "Back to history" becomes `<Button variant="outline" as-child><Link>Back to history</Link></Button>`.
- Run summary block: the 3-column grid moves into `<Card><CardHeader><CardTitle>Run summary</CardTitle></CardHeader><CardContent>…</CardContent></Card>`. Each label keeps `font-medium`. The error message line becomes `<Alert variant="destructive">` shown when `run.error_message` is truthy.
- Table: `<Table>` family.
- Action column: replace `actionClass()` with `actionVariant()`:
  - `applied` → `default`
  - `failed` → `destructive`
  - `skipped_*` → `secondary`
  - other → `outline`
- URL column: stays as raw `<a href target="_blank" rel="noopener">` (external link), but the class becomes `text-primary hover:underline`.
- Error message column: `text-red-700` becomes `text-destructive`.
- Time column: `text-gray-500` becomes `text-muted-foreground`.
- Empty state: same `<Card>` treatment as History.vue.

## Data flow and types

No changes. Props on each page stay identical. `useForm` on Config keeps its current shape. Inertia routing and Wayfinder action imports (`SweepConfigController`, `SweepHistoryController`) remain.

## Components added to imports per file

**Config.vue** (new): `Card`, `CardHeader`, `CardTitle`, `CardContent`, `Input`, `Select` family, `Button`, `Label`, `Switch`, `Badge`, `Alert`, `AlertDescription`, `Table` family, `InputError`, `Plus`, `Trash2` from `lucide-vue-next`.

**History.vue** (new): `Card`, `CardContent`, `Button`, `Badge`, `Table` family.

**HistoryDetail.vue** (new): `Card`, `CardHeader`, `CardTitle`, `CardContent`, `Button`, `Badge`, `Alert`, `AlertDescription`, `Table` family.

## Build sequence

1. `Config.vue` — biggest file, exercises every primitive type plus validation. Doing this first surfaces any unforeseen issues before touching the others.
2. `History.vue` — read-only list, smallest scope.
3. `HistoryDetail.vue` — read-only detail, mirrors patterns established in History.vue.

Each file is a single-file rewrite (big-bang, not section-by-section), keeping props, imports, breadcrumbs, and form/script logic structurally identical and only swapping the template.

## Testing & verification

**Existing tests must keep passing unchanged:**
- Pest feature tests covering `SensitivityLabelSweepConfigController` and `SensitivityLabelSweepHistoryController`.
- `composer run test` in full.
- `npm run lint`, `npm run format`, `npm run types:check`.
- `vendor/bin/pint --dirty --format agent` (no PHP changes expected, but run as a sanity check).
- `npm run build` must succeed.

**Manual UI verification before claiming done:**
- Run `composer run dev`, log in, visit each of the three pages.
- Confirm in light mode and dark mode:
  - Cards, tables, buttons, badges visually match `sensitivity-labels/Index.vue`.
  - No raw white-on-white or gray-on-gray illegibility in dark mode.
- Config save flow:
  - Submit a valid configuration; confirm success and persisted values.
  - Submit `interval_minutes: 0` and a malformed `bridge_url`; confirm `<InputError>` appears under each field, the offending inputs gain `border-destructive`, and the top-of-page `<Alert>` appears.
  - Add a rule with empty prefix; confirm nested-array error renders under the rule row.
- History list: empty state renders correctly when no runs exist; populated table renders run rows with correct badge variants.
- HistoryDetail: run summary card renders all three columns; error alert renders only when `run.error_message` is set; entries table renders action badges in the correct variants.

If the dev server cannot be started for any reason during implementation, that limitation must be reported explicitly rather than claimed-passed.

## Out of scope (deferred for separate work)

- Restructuring Config.vue's five sections as `<Tabs>` (Status / Default / Rules / Exclusions / Bridge).
- "Saved" toast notification on Config submit success.
- Updating `InputError.vue` to use `text-destructive` instead of `text-red-600` (out of scope because it touches every form in the app).
