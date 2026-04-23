# Sensitivity Label Bridge — Design Spec

**Date:** 2026-04-23
**Status:** Draft

## Overview

Partner365 currently discovers sensitivity labels and site→label mappings via Microsoft Graph (with CSV and Security & Compliance PowerShell fallbacks). What it cannot do from PHP is **write** sensitivity labels to SharePoint sites: in GCC High, Graph's `PATCH sensitivityLabel` silently no-ops and Graph's read returns null. The only reliable path is SharePoint CSOM via PnP.Framework, which is .NET-only.

This spec introduces `partner365-bridge`: a small stateless .NET 9 sidecar that Partner365 reaches over an internal Docker network to perform CSOM operations. On top of that primitive, Partner365 gains full label-management parity with the standalone Label365 tool: manual per-site applies from the UI, and a rule-based scheduled sweep that auto-applies labels to unlabeled sites.

### Goals

- Provide Partner365 with a way to write sensitivity labels to SharePoint sites (and authoritatively read them) in both commercial and GCC High tenants.
- Preserve Label365's proven CSOM behaviors (fast-path on current==target, systemic-failure abort, exclusion list) while rehoming rules, history, and UI inside Partner365.
- Keep the sidecar minimal — no database, no scheduler, no rule knowledge — so Partner365 remains the system of record and the sidecar can be safely restarted/replaced.
- Support both commercial and GCC High deployments via env-driven configuration that mirrors Partner365's existing `CloudEnvironment` enum.

### Non-goals

- Porting Label365's Razor Pages UI. All admin surface moves into Partner365's Vue/Inertia app.
- Multi-tenant bridge. One bridge instance serves one tenant — same model as Partner365 itself.
- Migrating existing Label365 installations. This is greenfield on the Partner365 side; Label365 continues to exist as a reference and standalone tool.
- Extending the sidecar to expose Graph operations. Partner365 does all Graph work directly.

## Architecture

Two containers, one shared Entra app registration, one shared certificate mount:

```
┌──────────────────────────────────────── docker-compose ────────────────────────────────────────┐
│                                                                                                │
│   ┌──────────────────────────────────────┐       ┌──────────────────────────────────────┐      │
│   │   partner365  (FrankenPHP / Octane)  │       │   bridge  (.NET 9 minimal API)       │      │
│   │                                      │       │                                      │      │
│   │   Vue UI · Laravel controllers       │──────▶│   Endpoints:                         │      │
│   │   Scheduled cmd: sensitivity:sweep   │  HTTP │     POST /v1/sites/label             │      │
│   │   Queued job:   ApplySiteLabelJob    │       │     POST /v1/sites/label:read        │      │
│   │   New models:   LabelRule, Site-     │       │     GET  /health                     │      │
│   │                 Exclusion, Sweep-    │       │                                      │      │
│   │                 Run, RunEntry        │       │   Stateless; cloud-aware via env     │      │
│   │                                      │       │   CSOM via PnP.Framework             │      │
│   │   Existing:  SensitivityLabel,       │       │                                      │      │
│   │             SiteSensitivityLabel,    │       │                                      │      │
│   │             ActivityLog, Graph svc   │       │                                      │      │
│   └──────────────────────────────────────┘       └──────────────────────────────────────┘      │
│                │                                                │                              │
│                └────── shared cert volume (read-only) ──────────┘                              │
│                                    │                                                           │
│                                    ▼                                                           │
│                      Entra app reg (adds cert credential                                       │
│                      + SharePoint Sites.FullControl.All                                        │
│                      + keeps existing Graph perms + client secret)                             │
│                                                                                                │
└────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Load-bearing properties

- **Bridge is stateless.** No DB, no scheduler, no rule knowledge. Only tenant + cert + cloud env from env vars. Keeps the sidecar's footprint tiny, allows blue/green restarts without data loss, and concentrates business logic in Partner365.
- **All business state lives in Partner365's database.** Rules, exclusions, sweep run history, catalog. Partner365 is the system of record.
- **Bridge is internal-only.** No published port. Partner365 reaches it via internal DNS (`http://bridge:8080`). Security model is network isolation + shared-secret header.
- **Single app registration.** Partner365's existing Entra app reg gains a certificate credential and SharePoint `Sites.FullControl.All`, on top of its existing Graph perms and client secret.

## Bridge — API surface

REST over HTTP/1.1, JSON bodies. Versioned under `/v1/` to allow future additions without breaking existing clients.

### `POST /v1/sites/label`

Applies a sensitivity label to a SharePoint site.

```json
// request
{
  "siteUrl": "https://gdotsg.sharepoint.us/sites/FinanceEXT",
  "labelId": "13833c6c-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
}

// 200 — applied successfully (or already at target; fastPath distinguishes)
{
  "siteUrl": "...",
  "labelId": "...",
  "fastPath": true
}
```

Optional query parameter `?overwrite=true` permits replacing an existing label. Scheduled sweeps always call with `overwrite=false` so they never stomp existing labels (mirrors Label365 invariant). Manual-apply UI calls with `overwrite=true`.

Error responses:

| Status | Shape | Meaning |
|---|---|---|
| 409 | `{ error: { code: "already_labeled" }}` | Site has a different label and `overwrite=false` |
| 404 | `{ error: { code: "not_found" }}` | Site URL does not resolve |
| 502 | `{ error: { code: "auth"\|"throttle"\|"network"\|"certificate"\|"unknown", message, requestId }}` | CSOM or auth failure |
| 401 | `{ error: { code: "missing_secret" }}` | `X-Bridge-Secret` header missing or wrong |

### `POST /v1/sites/label:read`

Authoritative CSOM read of a site's current label. Matters in GCC High where Graph returns null.

```json
// request
{ "siteUrl": "..." }

// 200
{ "siteUrl": "...", "labelId": "13833c6c-..." }     // or labelId: null if unlabeled
```

POST rather than GET because SharePoint URLs in query strings are reliability hazards (escaping).

### `GET /health`

Liveness/readiness. Does NOT call Graph/CSOM — just confirms the process is up and the cert loaded.

```json
{
  "status": "ok",
  "cloudEnvironment": "gcc-high",
  "certThumbprint": "abc123..."
}
```

Used by docker-compose healthcheck and by Partner365's Sweep Config page to confirm reachability. Exempt from `X-Bridge-Secret` requirement.

### What the API deliberately does NOT expose

- **No site enumeration.** Partner365 enumerates via Graph in `MicrosoftGraphService`. Duplicating in the bridge would create two sources of truth.
- **No label catalog.** Partner365's `SensitivityLabelService` already has a 3-tier catalog fallback (Graph → CSV → PowerShell).
- **No bulk operations.** Partner365's queue gives per-site retry and observability; bulk would reinvent it inside the bridge.

## Bridge — internal design

Minimal ASP.NET Core 9 project. No Razor Pages. No database. No Graph SDK dependency — pure CSOM.

### Project layout

```
bridge/
  Partner365.Bridge.csproj
  Program.cs                        # minimal API, endpoint wiring, DI
  Services/
    SharePointCsomService.cs        # CSOM SetLabelAsync / ReadLabelAsync
    CloudEnvironmentConfig.cs       # authority + CSOM resource by env
    CertificateLoader.cs            # loads PFX from mounted path
    ErrorClassifier.cs              # exception/message → error.code
    SharedSecretMiddleware.cs       # X-Bridge-Secret gate
  Models/
    SetLabelRequest.cs / Response.cs
    ReadLabelRequest.cs / Response.cs
    HealthResponse.cs
    ErrorResponse.cs
  Dockerfile
  appsettings.json                  # logging levels only
tests/Partner365.Bridge.Tests/      # xUnit
```

### Configuration — env vars only

| Env var | Example | Purpose |
|---|---|---|
| `BRIDGE_CLOUD_ENVIRONMENT` | `commercial` / `gcc-high` | Authority host + CSOM resource derivation |
| `BRIDGE_TENANT_ID` | GUID | Entra tenant |
| `BRIDGE_CLIENT_ID` | GUID | App reg (same one Partner365 uses) |
| `BRIDGE_ADMIN_SITE_URL` | `https://gdotsg-admin.sharepoint.us` | Admin URL; CSOM resource derived by stripping `-admin.` |
| `BRIDGE_CERT_PATH` | `/run/secrets/bridge.pfx` | Mounted PFX file |
| `BRIDGE_CERT_PASSWORD` | secret | PFX password (empty string allowed) |
| `BRIDGE_SHARED_SECRET` | random string | Required in `X-Bridge-Secret` header for all `/v1/*` calls |
| `ASPNETCORE_URLS` | `http://0.0.0.0:8080` | Kestrel bind address |

Bridge refuses to start if any of the first seven are missing — fail loud at boot, not on first request.

### Cloud environment mapping

| Env | Authority | CSOM resource derivation |
|---|---|---|
| `commercial` | `AzureAuthorityHosts.AzurePublicCloud` | `https://{host}.sharepoint.com/.default` from admin URL |
| `gcc-high` | `AzureAuthorityHosts.AzureGovernment` | `https://{host}.sharepoint.us/.default` |

The bridge does not call Graph at all, so only the CSOM resource matters. This keeps the dependency list tiny (no `Microsoft.Graph` package) and avoids half the GCC High trapdoors Label365 documents.

### `SharePointCsomService`

Two methods, both CSOM-only, with signatures that accept and return only primitives and POCOs:

```csharp
Task<SetLabelResult> SetLabelAsync(string siteUrl, string labelId, bool overwrite, CancellationToken ct);
Task<string?> ReadLabelAsync(string siteUrl, CancellationToken ct);    // null = unlabeled
```

Internals mirror Label365's proven `SetSensitivityLabelAsync`:

- Cert-based token via `ClientCertificateCredential` with the right `AuthorityHost`
- `PnP.Framework` `AuthenticationManager` wrapped per request (thread-safety of long-lived PnP tokens is unreliable)
- `Tenant.GetSitePropertiesByUrl(siteUrl, includeDetail: true)` to read current `SensitivityLabel2`
- `SetLabelAsync` fast-path when current == target; else guard on `overwrite`; else assign + `ExecuteQueryAsync`
- All `Microsoft.SharePoint.Client.*` and `Microsoft.Online.SharePoint.*` types stay **inside method bodies** (public signatures use only primitives and POCOs). Carries forward the Label365 CLAUDE.md invariant that prevented DI-load-time hangs.

### Error classification

Every 502 carries an `error.code` that classifies the underlying failure:

| Code | Triggers | Interpretation |
|---|---|---|
| `auth` | 401, 403, "unauthorized", "forbidden" | Cert/consent/permission problem. Same cause for every site. |
| `throttle` | 429, "throttl" | Graph/SPO rate limiting. Transient. |
| `network` | timeouts, connection resets | Transient. |
| `certificate` | cert-load failures, Azure.Identity errors | Deployment problem. Same cause for every site. |
| `unknown` | everything else | Could be per-site or systemic — Partner365 retries a bit, then gives up. |

Partner365's queue job uses these codes to decide retry vs. fail-fast (see Orchestration).

### Logging

- Structured stdout (picked up by `docker logs`).
- One line per request: method, path, status, duration ms, `requestId` (ULID, generated per request), `siteUrl`. No request body logging (avoids dumping label GUIDs en masse).
- Startup log: cert thumbprint, cloud env, kestrel bind. Fatal if cert load fails.
- `requestId` is echoed in response headers so Partner365 can cross-reference bridge stdout with Laravel logs.

## Partner365 — data model

Five migrations + five models + five factories. All in `app/Models/` following existing Eloquent conventions.

### `label_rules`

Title-prefix → label mapping. Mirrors Label365's `LabelRule`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `prefix` | string(100) | Case-insensitive match; empty rejected |
| `label_id` | string(50) | Graph label GUID; no FK to `sensitivity_labels` |
| `priority` | integer | 1-based; reassigned 1..N on save to close gaps |
| `created_at`, `updated_at` | | |

Unique index on `priority`. Index on `prefix`.

Label GUIDs are plain strings (no FK) so Purview renames/deletes do not cascade into rules or history. Display resolution is a soft lookup with GUID fallback, same pattern as Label365's catalog design.

### `site_exclusions`

URL substring patterns to skip. Mirrors Label365's `SiteExclusion`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `pattern` | string(500) | Case-insensitive substring match against site URL |
| `created_at`, `updated_at` | | |

Seeded on first migration with `/sites/contentTypeHub` (Label365's default — a system site that cannot hold a sensitivity label).

### `label_sweep_runs`

One row per sweep execution. Mirrors `RunLog`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `started_at` | datetime | UTC |
| `completed_at` | datetime nullable | |
| `total_scanned` | int | |
| `already_labeled` | int | Skipped because site already had a label |
| `applied` | int | |
| `skipped_excluded` | int | Explicit counter for exclusion hits |
| `failed` | int | |
| `status` | string(20) | `running` / `success` / `partial_failure` / `failed` / `aborted` |
| `error_message` | text nullable | Set when `status` = `failed`/`aborted` |
| `created_at`, `updated_at` | | |

Retention: sweep command trims to the most recent 500 at end of run.

### `label_sweep_run_entries`

One row per site processed in a run. Mirrors `RunLogEntry`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `label_sweep_run_id` | bigint FK → `label_sweep_runs` | `onDelete('cascade')` |
| `site_url` | string(500) | Denormalized — rows survive site deletion |
| `site_title` | string(300) | Denormalized |
| `action` | string(20) | `applied` / `skipped_labeled` / `skipped_excluded` / `skipped_no_match` / `skipped_aborted` / `failed` |
| `label_id` | string(50) nullable | Label GUID applied (or attempted) |
| `matched_rule_id` | bigint nullable FK → `label_rules` | Nullable; no cascade on delete so rule deletion doesn't break history |
| `error_message` | text nullable | |
| `error_code` | string(20) nullable | Forwarded from bridge 502s |
| `processed_at` | datetime | |

Index on `label_sweep_run_id`.

### Settings (reuses existing `Setting` model)

New `sensitivity_sweep` group, no new table:

| Key | Example | Purpose |
|---|---|---|
| `sensitivity_sweep.enabled` | bool | Pause switch |
| `sensitivity_sweep.interval_minutes` | int, default 90, coerced to 90 if ≤ 0 | Sweep cadence |
| `sensitivity_sweep.default_label_id` | GUID | Applied when no rule matches |
| `sensitivity_sweep.bridge_url` | `http://bridge:8080` | Where Partner365 reaches the bridge |
| `sensitivity_sweep.bridge_shared_secret` | string | Matches `BRIDGE_SHARED_SECRET` env |

### What we are explicitly NOT adding

- **No new `labels` table.** Partner365's existing `sensitivity_labels` holds the catalog. Rules reference label GUIDs as strings with soft lookup.
- **No new `sites` tracking table.** Partner365's existing `site_sensitivity_labels` tracks site→label. The sweep reads from there and writes updates back via `SiteSensitivityLabel::updateOrCreate()` after a successful bridge call.
- **No new activity-log table.** Sweep-level events (rule/exclusion changes, run start, abort, manual apply) route through existing `ActivityLogService` with new `ActivityAction` enum values (`label_applied`, `rule_changed`, `exclusion_changed`, `sweep_ran`, `sweep_aborted`). Fine-grained per-site results live in `label_sweep_run_entries` only.

### New `ActivityAction` enum values

Add to `app/Enums/ActivityAction.php`:

- `label_applied`
- `rule_changed`
- `exclusion_changed`
- `sweep_ran`
- `sweep_aborted`

## Partner365 — sweep orchestration

Scheduled command + queued per-site jobs. Mirrors Partner365's existing `sync:partners` / `sync:guests` pattern.

### Scheduled command — `sensitivity:sweep`

Registered in `routes/console.php`:

```php
Schedule::command('sensitivity:sweep')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

`everyMinute()` + internal interval check means admins change cadence without restarting the scheduler. Mirrors Label365's "config re-read every iteration" invariant.

Command flow (`app/Console/Commands/SensitivitySweepCommand.php`):

1. Guard: `sensitivity_sweep.enabled = false` → log "paused", return.
2. Guard: `default_label_id` unset → log "not configured", return.
3. Guard: `now() - last_run.started_at < interval_minutes` → return quietly. Bypassed by `--force`.
4. Pre-flight: `BridgeClient::health()`. Failure → mark run `failed` with message, notify admins, return.
5. Create `LabelSweepRun` with `status=running`, `started_at=now()`.
6. Enumerate candidate sites from `site_sensitivity_labels` (already populated by existing site sync). Filter to URLs under `/sites/` or `/teams/`.
7. Apply exclusions: drop sites whose URL contains any `SiteExclusion.pattern`. Increment `skipped_excluded` counter, write entries with `action=skipped_excluded`, delete matching rows from `site_sensitivity_labels`.
8. For each remaining site:
   - Already labeled → entry with `action=skipped_labeled`, no job dispatched, increment `already_labeled`.
   - Unlabeled → walk `LabelRule::orderBy('priority')`, first match by `stripos($title, $prefix) === 0` wins; no match → default label.
9. Dispatch `ApplySiteLabelJob::dispatch($runId, $siteUrl, $siteTitle, $labelId, $matchedRuleId)` onto Partner365's default queue per site needing a label.
10. Dispatch `CompleteSweepRunJob::dispatch($runId)->delay(now()->addMinutes(30))` to sum counters, set final status (`success` / `partial_failure` / `aborted` — aborted runs keep the status set by `AbortSweepRunJob`), write a single `ActivityLog(sweep_ran, summary)` entry with the aggregate counts, and trim run history to the most recent 500.

The `--force` flag bypasses the interval guard. The `--dry-run` flag does steps 1–8 but writes all entries as `skipped_*` actions and does not dispatch apply jobs — used for admin confidence-building before enabling the feature.

### Queued job — `ApplySiteLabelJob`

Implements `ShouldQueue`. `$tries = 4`, `$backoff = [30, 120, 600]`, `$timeout = 120`. Dispatched onto Partner365's default queue (same place `sync:partners` jobs live).

Constructor: `(int $runId, string $siteUrl, string $siteTitle, string $labelId, ?int $matchedRuleId)`.

`handle(BridgeClient $bridge)`:

1. Early short-circuit: reload `LabelSweepRun` by `$runId`. If `status=aborted`, write `LabelSweepRunEntry(action: skipped_aborted)` and return. This lets an in-flight abort stop already-queued jobs without needing to flush the queue.
2. Call `$bridge->setLabel($siteUrl, $labelId, overwrite: false)`.
3. 200 → write `action: applied`, update `SiteSensitivityLabel`. No `ActivityLog` write here — per-site applies during a sweep live in `label_sweep_run_entries` only. The sweep's `sweep_ran` summary (written when the run completes) is the single `ActivityLog` entry that represents the whole run.
4. 409 → write `action: skipped_labeled`, no retry. (Someone labeled it between enumeration and the call.)
5. 404 → write `action: failed`, `error_code: not_found`, no retry.
6. 502 `throttle`/`network` → rethrow. Laravel retries per `$backoff`. On final exhaustion, write `action: failed`.
7. 502 `auth`/`certificate` → write `action: failed`, no retry. Increment `Cache::increment("sweep:{$runId}:systemic_failures")`. If counter ≥ 3, dispatch `AbortSweepRunJob($runId)`.
8. 502 `unknown` or other exceptions → retry up to `$tries`; on exhaustion write `action: failed`.

Note on action values: `skipped_aborted` is a new value added alongside the original set (`applied`, `skipped_labeled`, `skipped_excluded`, `skipped_no_match`, `failed`), specifically for queue-draining after an abort.

### `AbortSweepRunJob`

- Marks `LabelSweepRun` as `status=aborted`, `completed_at=now()`, `error_message="Aborted after 3 systemic failures: ..."`
- Logs `ActivityLog(sweep_aborted)`
- Sends Laravel notification to all admin users via the project's existing notification channel

The job does NOT try to remove queued `ApplySiteLabelJob` entries from Redis — each job's handle-time run-status check is the drain mechanism. Trade-off: queued jobs still consume a worker cycle each, but they exit cheaply (one DB read) and the logic is an order of magnitude simpler than managing per-run queues.

### `BridgeClient` (`app/Services/BridgeClient.php`)

Thin HTTP wrapper. No logic beyond HTTP + exception translation.

```php
public function setLabel(string $siteUrl, string $labelId, bool $overwrite = false): SetLabelResult;
public function readLabel(string $siteUrl): ?string;
public function health(): BridgeHealth;
```

Uses `Http::withHeaders(['X-Bridge-Secret' => Setting::get('sensitivity_sweep', 'bridge_shared_secret')])->baseUrl(Setting::get('sensitivity_sweep', 'bridge_url'))`.

Maps bridge responses to typed exceptions:

| Bridge response | PHP exception |
|---|---|
| 2xx | none (returns result) |
| 409 | `BridgeLabelConflictException` (signals "skipped_labeled" to caller) |
| 404 | `BridgeSiteNotFoundException` |
| 502 `auth` | `BridgeAuthException` |
| 502 `throttle` | `BridgeThrottleException` |
| 502 `network` | `BridgeNetworkException` |
| 502 `certificate` | `BridgeConfigException` |
| connection failure / 5xx | `BridgeUnavailableException` |
| other | `BridgeUnknownException` |

### Manual-apply flow (synchronous, UI-driven)

Operator clicks "Apply label" in Partner365's site detail:

- Inertia POST to `SensitivityLabelController::applyToSite($siteId, $labelId)`
- Controller: RBAC check via `UserRole::canManage()`. Admin and operator allowed; viewer rejected.
- `$bridge->setLabel($site->url, $labelId, overwrite: true)` **synchronously** (user waiting; single site; no benefit from queueing).
- Success → update `SiteSensitivityLabel`, log `ActivityLog(label_applied, actor_id: $user->id)`, flash success.
- Typed exception → flash a user-friendly message; do not retry automatically:

| Exception | Flash message |
|---|---|
| `BridgeAuthException` | "The bridge can't authenticate to SharePoint. Check the sidecar's certificate and app permissions." |
| `BridgeThrottleException` | "SharePoint is rate-limiting requests. Try again in a minute." |
| `BridgeUnavailableException` | "The label sidecar is not reachable. Check deployment health." |
| `BridgeConfigException` | "The sidecar's certificate is not loading. Contact an administrator." |
| other | "Label change failed: {message}" |

## Partner365 — UI

Extends existing `sensitivity-labels/` and `sharepoint-sites/` Vue pages; adds three new pages under `sensitivity-labels/Sweep/`.

### Existing page: `sharepoint-sites/Show.vue`

Add a "Sensitivity label" card:

- Current label name (resolved from `sensitivity_labels`), "As of Xm ago" timestamp.
- "Refresh authoritative state" button → `SharePointSiteController::refreshLabel()` → `$bridge->readLabel()` → update `SiteSensitivityLabel`. Critical in GCC High where Graph returns null.
- "Change label" button (admin/operator only). Opens a dialog with a `<select>` of site-applicable labels. Confirm runs the synchronous manual-apply flow.
- If site matches a current `SiteExclusion`, show "Excluded from automated sweeps" badge.

### Existing page: `sensitivity-labels/Index.vue`

Add a "Rules using this label" column showing `LabelRule::where('label_id', $label->label_id)->count()`. Lets admins see which labels are wired into the sweep.

### New page: `sensitivity-labels/Sweep/Config.vue` (admin only)

Four sections, one Save button at bottom:

1. **Sweep status**
   - Enabled toggle → `sensitivity_sweep.enabled`
   - Interval (minutes), default 90, min 30
   - "Last run" summary with link to history
   - Bridge health indicator: calls `BridgeClient::health()` on page load, green/red pill with cloud env + cert thumbprint in tooltip. Red banner with HTTP error text if unreachable.
2. **Default label** — `<select>` from `sensitivity_labels` (site-applicable only) → `sensitivity_sweep.default_label_id`.
3. **Prefix rules** — table with Priority · Prefix · Label (dropdown) · Remove columns. Add-rule button. Reorderable via drag or up/down. Save reassigns priority 1..N to close gaps. Empty prefixes rejected.
4. **Site exclusions** — table with Pattern · Added · Remove. Case-insensitive substring match. Empty patterns rejected.

Form uses Inertia's `useForm()`.

### New page: `sensitivity-labels/Sweep/History.vue` (all roles, read-only)

Table of `LabelSweepRun`: Started · Duration · Scanned · Applied · Skipped · Failed · Status badge. Sortable (default: Started desc). Filter input. Row click → detail.

### New page: `sensitivity-labels/Sweep/HistoryDetail.vue`

Table of `LabelSweepRunEntry` for one run. Columns: Site Title · URL (truncated, clickable, full in tooltip) · Action · Label Applied · Matched Rule · Error. Color coded: green=applied, yellow=skipped, red=failed. Default sort: `processed_at` asc.

### Navigation

Under existing "Sensitivity labels" sidebar section:

- Catalog (existing Index)
- Sweep → Config (admin only)
- Sweep → History (all)

### Controllers

- `SensitivityLabelSweepConfigController` — `show`, `update` (admin-only via `CheckRole`)
- `SensitivityLabelSweepHistoryController` — `index`, `show` (viewer and up)
- `SensitivityLabelController::applyToSite`, `::refreshLabel` — new methods (operator and up)

## Auth boundary between Partner365 and the bridge

### Layer 1 — network isolation

Bridge binds to the internal docker-compose network only. No `ports:` published. Partner365 reaches `http://bridge:8080` via internal DNS. Nothing outside the Docker host reaches the bridge. No TLS between Partner365 and the bridge — internal-network-only + shared secret is a better tradeoff than self-signed cert juggling.

### Layer 2 — shared-secret header

Every request to `/v1/*` requires `X-Bridge-Secret: <random>`. `/health` is exempt.

- Generated once at deploy (`openssl rand -hex 32`).
- Stored in `BRIDGE_SHARED_SECRET` env (bridge container) and `sensitivity_sweep.bridge_shared_secret` setting (Partner365), writable via Sweep Config page.
- Bridge middleware rejects missing/wrong header with `401`, constant-time compare via `CryptographicOperations.FixedTimeEquals`.

### Why not mTLS or JWT

- mTLS: cert lifecycle inside compose is a known papercut.
- JWT: needs a shared signing key anyway — same operational profile as the shared secret, more moving parts.

Shared-secret over a private network is the standard sidecar pattern and matches Partner365's general opsec posture.

### Rotation

1. Generate new secret. Update `BRIDGE_SHARED_SECRET` env and restart bridge container.
2. Update `sensitivity_sweep.bridge_shared_secret` via Sweep Config page.

Brief overlap window between steps 1 and 2 is acceptable — sweep jobs retry with backoff.

### Request ID

Every bridge request is tagged with a ULID `requestId`. Logged on both sides and echoed in response headers, so a 502 can be cross-referenced between bridge stdout and Laravel logs.

## Error handling invariants

- Every `LabelSweepRunEntry` with `action=failed` has a non-null `error_code` and `error_message`.
- Every `LabelSweepRun` with `status=aborted` has a non-empty `error_message`.
- `ActivityLog` gets exactly one row per admin-visible event: rule change, exclusion change, sweep run completion (single `sweep_ran` summary with aggregate counts), sweep abort, manual apply. Individual per-site applies **inside** a sweep do not spam `ActivityLog` — those are in `label_sweep_run_entries`.
- Throttle and network errors do NOT count toward systemic-failure abort. Only `auth` and `certificate` do.
- A user clicking "Apply label" manually never triggers an automatic retry. The flash message prompts them to decide.

## Testing

### Bridge — xUnit + Moq

New `tests/Partner365.Bridge.Tests` project. Unit tests only; no integration tests in CI.

- `CertificateLoaderTests` — loads PFX from file, rejects missing file, rejects wrong password. CI generates a throwaway self-signed PFX in a test fixture.
- `CloudEnvironmentConfigTests` — commercial and gcc-high resolve to correct authority and CSOM resource. Strip-`-admin.` derivation for representative hostnames.
- `SharedSecretMiddlewareTests` — 401 without header, 401 with wrong value, 200 with correct, `/health` always open.
- `ErrorClassifierTests` — parameterized over known exception shapes and messages → expected `error.code`.

CSOM calls themselves are not unit-tested against a mock SharePoint. `SharePointCsomService` delegates to an internal `ICsomOperations` interface (`GetSiteLabelAsync`, `SetSiteLabelAsync`); the service's orchestration (fast-path guard, overwrite gate) is unit-tested; the CSOM interactions are covered by the manual integration harness.

### Partner365 — Pest

All in `tests/Feature/`. External HTTP calls faked with `Http::fake()` or `BridgeClient` test double.

- `SensitivitySweepCommandTest`
  - Respects `sensitivity_sweep.enabled=false` (no jobs dispatched)
  - Respects interval guard; `--force` bypasses it
  - Applies exclusions (entry written, row removed from `site_sensitivity_labels`)
  - Rule matching by prefix, priority order, default-label fallback
  - Dispatches one `ApplySiteLabelJob` per unlabeled site (assert via `Bus::fake()`)
  - Pre-flight health failure → `failed` run, zero jobs, admin notification
- `ApplySiteLabelJobTest`
  - 200 → `applied` entry, `SiteSensitivityLabel` updated, `ActivityLog` entry
  - 409 → `skipped_labeled`, no retry
  - 502 `throttle` → retry with backoff, eventual `failed` if exhausted
  - 502 `auth` → no retry, systemic counter incremented; 3rd failure dispatches `AbortSweepRunJob`
  - Unknown exception → retries exhausted → `failed`
- `AbortSweepRunJobTest` — marks run aborted, sends admin notification.
- `ApplySiteLabelJobAbortDrainTest` — job whose `LabelSweepRun` is already `aborted` at handle-time writes `skipped_aborted` entry and does not call the bridge.
- `SensitivityLabelSweepConfigControllerTest` — admin can save; empty prefixes/patterns rejected; operator/viewer get 403; priority reassignment closes gaps.
- `SensitivityLabelSweepHistoryControllerTest` — all roles read; entries ordered chronologically.
- `SensitivityLabelManualApplyTest` — admin/operator apply succeeds; viewer 403; bridge 502 auth surfaces friendly flash.

`BridgeClient` is faked via `$this->mock(BridgeClient::class)`. A separate narrow unit test for `BridgeClient` uses `Http::fake()` to verify header, URL, and response-to-exception mapping.

### Manual integration harness

A `bridge/dev/validate.md` checklist the deploy runbook points to:

1. `docker compose up -d` with real tenant env vars.
2. Briefly expose bridge port, `curl http://localhost:<exposed>/health`, expect 200 with matching `cloudEnvironment`. Unexpose.
3. Pick a test site. `BridgeClient::readLabel()` via `php artisan tinker` — confirm authoritative label matches SharePoint admin center.
4. Apply a known label via Partner365 UI. Verify in SharePoint admin center within 30s.
5. Seed a rule. Run `php artisan sensitivity:sweep --force`. Verify a run is created with expected entries.
6. Break the cert mount (swap to wrong cert). Trigger a sweep. Verify abort kicks in after 3 failures and admin notification arrives.

### Coverage goal

Not a percentage. Every `LabelSweepRunEntry.action` value and `error_code` produced by live paths has a test that produces it. Every `LabelSweepRun.status` transition has a test that exercises it. Every `Setting::get('sensitivity_sweep', ...)` key is exercised by the config controller test.

## Deployment mechanics

### `docker-compose.yml` additions

```yaml
services:
  app:
    environment:
      SENSITIVITY_SWEEP_BRIDGE_URL: http://bridge:8080
    depends_on:
      bridge:
        condition: service_healthy
    networks: [internal]

  bridge:
    build:
      context: ./bridge
      dockerfile: Dockerfile
    image: partner365-bridge:latest
    environment:
      BRIDGE_CLOUD_ENVIRONMENT: ${MICROSOFT_GRAPH_CLOUD_ENVIRONMENT}
      BRIDGE_TENANT_ID: ${MICROSOFT_GRAPH_TENANT_ID}
      BRIDGE_CLIENT_ID: ${MICROSOFT_GRAPH_CLIENT_ID}
      BRIDGE_ADMIN_SITE_URL: ${SHAREPOINT_ADMIN_SITE_URL}
      BRIDGE_CERT_PATH: /run/secrets/bridge.pfx
      BRIDGE_CERT_PASSWORD: ${BRIDGE_CERT_PASSWORD}
      BRIDGE_SHARED_SECRET: ${BRIDGE_SHARED_SECRET}
      ASPNETCORE_URLS: http://0.0.0.0:8080
    volumes:
      - ${BRIDGE_CERT_HOST_PATH}:/run/secrets/bridge.pfx:ro
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://localhost:8080/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
    restart: unless-stopped
    networks: [internal]
    # Deliberately NO `ports:` — bridge is internal-only.

networks:
  internal:
    driver: bridge
```

`depends_on` with `service_healthy` catches "bridge cert is broken" at `docker compose up`, not first sweep.

### `bridge/Dockerfile`

Standard .NET 9 multi-stage, runs as the `aspnet` base image's default non-root user.

```dockerfile
FROM mcr.microsoft.com/dotnet/sdk:9.0 AS build
WORKDIR /src
COPY Partner365.Bridge.csproj .
RUN dotnet restore
COPY . .
RUN dotnet publish -c Release -o /app --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:9.0
# curl is required for the docker-compose healthcheck; the aspnet image ships without it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=build /app .
EXPOSE 8080
ENTRYPOINT ["dotnet", "Partner365.Bridge.dll"]
```

Partner365's own `Dockerfile` is unchanged — no .NET runtime layered in.

### App registration updates

Partner365's existing Entra app reg gains three deltas:

1. **Certificate credential.** Upload the `.cer` public key to Certificates & secrets. Admins generate the cert with `New-SelfSignedCertificate` and keep the `.pfx` alongside `.env`. Existing client secret remains intact — Partner365's Graph path still uses it.
2. **Graph `Sites.FullControl.All`** (Application). Confirm grant (may already be present).
3. **SharePoint Online `Sites.FullControl.All`** (Application). Required for CSOM tenant admin. Admin consent required.

The Partner365 docs get a new section **"Sensitivity labels sidecar setup"** under Admin that walks through these three deltas. PowerShell snippets are a direct port from Label365's `docs/deployment-guide.md` Steps 2.2–2.4, rewritten as "add these grants to your existing app reg."

### New env vars (`.env.example`)

```
SHAREPOINT_ADMIN_SITE_URL=https://contoso-admin.sharepoint.com
BRIDGE_CERT_HOST_PATH=./storage/bridge/bridge.pfx
BRIDGE_CERT_PASSWORD=
BRIDGE_SHARED_SECRET=  # openssl rand -hex 32
```

`MICROSOFT_GRAPH_CLOUD_ENVIRONMENT`, `MICROSOFT_GRAPH_TENANT_ID`, `MICROSOFT_GRAPH_CLIENT_ID` are already in Partner365's `.env` — reused, not re-added.

### Rollout order

1. Deploy the bridge image. Leave `sensitivity_sweep.enabled=false`.
2. Admin opens Sweep Config page; bridge health indicator confirms reachability and correct cloud env.
3. Admin configures default label, at least one test rule, and an exclusion.
4. Run `php artisan sensitivity:sweep --force --dry-run` — enumerate + match, no apply. Review the generated run detail.
5. If dry-run looks right, flip `sensitivity_sweep.enabled=true`.
6. First real sweep is slow (per Label365: 10–30 min against ~2000 sites); subsequent sweeps hit fast paths and finish in minutes.

### Rollback

- Disable enforcement: `sensitivity_sweep.enabled=false`. UI still shows historical runs; no new sweeps start.
- Remove bridge: `docker compose stop bridge`, remove from compose. Manual-apply buttons fail with typed exceptions and friendly messages; scheduled command fails at pre-flight health check. Existing label data in Partner365 stays intact.
- Nothing in M365 needs to be rolled back — labels applied by the sidecar are indistinguishable from labels applied any other way.

## Open questions

None. Defaults match Label365's proven behavior where applicable; departures (stateless sidecar, Partner365 owns orchestration, shared app reg) are deliberate simplifications.
