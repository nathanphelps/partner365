# Sensitivity-Label Enumeration via Bridge — Design

**Date:** 2026-04-25
**Status:** Draft, awaiting user review
**Scope:** Move tenant sensitivity-label enumeration off the Laravel host and into the bridge. Remove the CSV fallback and the Laravel-side PowerShell path entirely. The bridge becomes the single non-Graph source of truth for tenant labels.

## Background

The current `SensitivityLabelService::fetchLabelsWithFallback()` runs a four-tier strategy chain when syncing the tenant's sensitivity labels:

1. **Microsoft Graph** — `/security/informationProtection/sensitivityLabels`. Works in commercial. **Unavailable in GCC High.**
2. **CSV file** — reads `Get-Label | Export-Csv` output from the path in `COMPLIANCE_LABELS_CSV_PATH`. Requires an admin to manually export and place the file.
3. **PowerShell on the Laravel host** — `CompliancePowerShellService` invokes `Get-Label` via the `ExchangeOnlineManagement` module using a cert deployed on the Laravel/IIS host.
4. **Stub creation** — when nothing else returns labels, site-level sync creates label stubs from per-site `SensitivityLabel` properties (which the SharePoint Admin API exposes even in GCC High).

This works but spreads cert and PowerShell deployment across the Laravel host AND the bridge host. The CSV path is operationally awkward (admin has to refresh it). The Laravel-side PowerShell path duplicates capability that the bridge could just as easily own.

The bridge is a .NET 9 sidecar service running on Windows that already holds a cert (`partner365-compliance.pfx`) for SharePoint CSOM operations. It has three endpoints today: `GET /health`, `POST /v1/sites/label`, `POST /v1/sites/label:read`. It does not expose any way to enumerate the tenant's available labels.

## Goals

1. **Remove the CSV fallback entirely.** Code, config keys, env vars, test fixtures, and the parser methods that consume them.
2. **Remove the Laravel-side PowerShell path entirely.** No `ExchangeOnlineManagement` module on the Laravel host, no compliance cert deployed there.
3. **Add a bridge endpoint** that enumerates the tenant's sensitivity labels using `Get-Label` via `Microsoft.PowerShell.SDK` in-process.
4. **Keep the Graph tier.** It's the fast path for commercial; no reason to disturb it.
5. **Keep the stub-from-site fallback.** Last-resort behavior when both Graph and the bridge are unreachable.

## Non-goals

- No change to label *application* (writing labels to sites). The bridge already does that via CSOM and we leave it untouched.
- No move of bridge to MIP SDK. A spike (deleted from the repo before this spec was written) demonstrated that `Microsoft.InformationProtection.File 1.16.126`'s GCC High support requests a token to the commercial cloud's `o365syncservice.com`, gets `AccessDeniedException` from the policy service, and would also ship 30+ MB of native code including OpenSSL 3 redist. PowerShell-via-SDK is the pragmatic, supported path.
- No commercial-cloud regression testing. This change is GCC-driven, but commercial keeps working because the Graph tier is preserved.
- No interactive auth. Bridge stays cert-based.

## Architecture

### Bridge — new `LabelEnumerationService`

Bridge gains a service class that encapsulates label enumeration. The service depends on an `IPowerShellRunner` interface (not directly on `PowerShell.Create()`) so unit tests can inject a fake. The runner contract is roughly:

```csharp
public interface IPowerShellRunner
{
    Task<IReadOnlyList<PSObject>> InvokeAsync(string command, IDictionary<string, object>? parameters = null);
}
```

The production implementation creates a fresh runspace per request, imports `ExchangeOnlineManagement`, runs `Connect-IPPSSession` with cert + tenant + cloud-environment-derived `-ExchangeEnvironmentName`, runs the requested command, captures errors via the runspace's `Streams.Error`, and disposes the runspace. No long-lived runspaces.

The service:

1. Calls the runner to invoke `Get-Label -IncludeDetailedLabelActions`.
2. Maps each `PSObject` to a `BridgeLabel` DTO (fields below).
3. Caches the resulting list in-memory with a 5-minute TTL keyed by the tenant id (which is per-bridge constant). Cache miss re-invokes the runner; cache hit returns the cached list.
4. Surfaces classified failures (`BridgeAuthException` for token problems, `BridgeUpstreamException` for `Get-Label` errors) as 401/502 in the endpoint layer.

### Bridge — `GET /v1/labels` endpoint

Same `X-Bridge-Secret` header auth as existing endpoints. Same classified-error response shape (`ErrorResponse { code, message }`).

Response on success:

```json
{
  "source": "powershell",
  "fetchedAt": "2026-04-25T13:30:00Z",
  "labels": [
    {
      "id": "00000000-0000-0000-0000-000000000000",
      "name": "Confidential",
      "description": "Limited distribution",
      "tooltip": "Use for partner-only docs",
      "color": "#4472C4",
      "priority": 5,
      "parentId": null,
      "isActive": true,
      "contentTypes": ["File", "Email", "Site", "UnifiedGroup"],
      "encryption": true,
      "watermark": false,
      "headerFooter": false
    }
  ]
}
```

The shape matches what `SensitivityLabelService::syncLabels()` already consumes from Graph and CSV today, so no Laravel-side mapper changes are needed.

### Bridge — auth and cert

- Reuses existing `BRIDGE_CERT_HOST_PATH` + `BRIDGE_CERT_PASSWORD` (or `BRIDGE_CERT_THUMBPRINT`) — no new cert.
- Reuses existing `BRIDGE_TENANT_ID`, `BRIDGE_CLIENT_ID`, `BRIDGE_CLOUD_ENVIRONMENT`.
- `BRIDGE_CLOUD_ENVIRONMENT` maps to PS module's `-ExchangeEnvironmentName`:
  - `commercial` → `O365Default`
  - `gcc-high` → `O365USGovGCCHigh`

### Laravel — `BridgeClient` gets `getLabels()`

One method added to `app/Services/BridgeClient.php`:

```php
public function getLabels(): array
{
    $response = $this->http()
        ->withHeaders(['X-Bridge-Secret' => $this->sharedSecret()])
        ->timeout(30)
        ->get($this->baseUrl() . '/v1/labels');

    if ($response->failed()) {
        throw $this->classifyException($response);
    }

    return $response->json('labels') ?? [];
}
```

Same exception classification as existing methods (`BridgeConfigException` for 401, `BridgeUpstreamException` for 5xx, etc.). No new exception types.

### Laravel — sync chain rewrite

`SensitivityLabelService::fetchLabelsWithFallback()` becomes a three-tier chain:

1. **Graph.** Existing call. On success, returns `['labels' => [...], 'source' => 'graph']`.
2. **Bridge.** Calls `BridgeClient::getLabels()`. On success, returns `['labels' => [...], 'source' => 'bridge']`.
3. **Stubs.** Existing fallback — returns `null`, downstream `syncSiteLabels()` creates label stubs.

Each tier is wrapped in try/catch. Failures log a warning naming the tier and continue to the next. The "via X" hint shown in the command output (`Labels synced: N (via bridge)`) comes from the `source` field.

## File-level changes

### Bridge — files to create

| File | Purpose |
|---|---|
| `bridge/Partner365.Bridge/Services/IPowerShellRunner.cs` | Interface for PS invocation, enables unit testing without a real runspace |
| `bridge/Partner365.Bridge/Services/PowerShellSdkRunner.cs` | Production impl — fresh runspace per request, imports `ExchangeOnlineManagement`, runs `Connect-IPPSSession` |
| `bridge/Partner365.Bridge/Services/LabelEnumerationService.cs` | Cache + map runner output to DTOs |
| `bridge/Partner365.Bridge/Models/LabelsResponse.cs` | Top-level JSON response (`source`, `fetchedAt`, `labels`) |
| `bridge/Partner365.Bridge/Models/BridgeLabel.cs` | Single label DTO with the fields shown above |
| `bridge/Partner365.Bridge.Tests/Services/LabelEnumerationServiceTests.cs` | Unit tests with a fake runner |
| `bridge/Partner365.Bridge.Tests/LabelEndpointTests.cs` | WebApplicationFactory tests for the endpoint |

### Bridge — files to modify

| File | What |
|---|---|
| `bridge/Partner365.Bridge/Partner365.Bridge.csproj` | Add `<PackageReference Include="Microsoft.PowerShell.SDK" Version="7.4.x" />` |
| `bridge/Partner365.Bridge/Program.cs` | Register `IPowerShellRunner` + `LabelEnumerationService` in DI; map `GET /v1/labels` route |
| `bridge/windows/README.md` | Add to "Prerequisites": `Install-Module ExchangeOnlineManagement -Scope AllUsers -Force`; add the `Exchange.ManageAsApp` permission and Compliance role assignment |
| `bridge/windows/Install-PartnerBridge.ps1` | Append the `Install-Module` step to the installer's bootstrap section |

### Laravel — files to create

None.

### Laravel — files to modify

| File | What |
|---|---|
| `app/Services/BridgeClient.php` | Add `getLabels()` method |
| `app/Services/SensitivityLabelService.php` | Replace CSV+PS branches with a single bridge branch; update tier ordering and the `source` strings; remove the `CompliancePowerShellService` dependency from the constructor |
| `config/graph.php` | Remove `'labels_csv_path'`, `'compliance_certificate_path'`, `'compliance_certificate_password'` keys |
| `.env.example` | Remove `COMPLIANCE_LABELS_CSV_PATH`, `COMPLIANCE_CERTIFICATE_PATH`, `COMPLIANCE_CERTIFICATE_PASSWORD` |
| `tests/Feature/Services/SensitivityLabelServiceTest.php` | Delete CSV/PS test cases; add bridge-path test cases (mocked via `Http::fake()`) |
| `tests/Feature/Services/BridgeClientTest.php` (or wherever bridge tests live) | Add `getLabels()` happy-path + classified-error tests |
| `app/Http/Controllers/Admin/GraphController.php` and `resources/js/pages/admin/Graph.vue` | If they expose form fields for the deleted env vars, remove them and their validation rules. Verify during implementation. |

### Laravel — files to delete

| File | Why |
|---|---|
| `app/Services/CompliancePowerShellService.php` | Both the CSV parsing and direct-PS call paths it served are gone. Verify no other callers via grep before deleting. |
| `tests/Feature/Services/CompliancePowerShellServiceTest.php` | Subject under test is gone |
| `tmp/Labels.csv` | Test fixture no longer used |

### Database — runtime cleanup

If the `settings` table holds any of `(group=graph, key=labels_csv_path)`, `(group=graph, key=compliance_certificate_path)`, or `(group=graph, key=compliance_certificate_password)`, delete them in a migration so dead rows don't linger. (Verify presence in implementation; if no rows exist the migration is a no-op and that's fine.)

## Deployment order

1. **Ship the bridge update first.** `GET /v1/labels` becomes live. Verify with `curl -H "X-Bridge-Secret: <secret>" http://127.0.0.1:5300/v1/labels` that it returns labels in the expected shape. Laravel still uses the old chain, so a bridge endpoint failure doesn't break sync.
2. **Then ship the Laravel update.** New `BridgeClient::getLabels()`, new chain, removed dead code. Run `php artisan sync:sensitivity-labels` and confirm output reads `Labels synced: N (via bridge)`.
3. **Rollback path.** If step 2 fails, revert just the Laravel deploy; the bridge endpoint can remain live (it's idempotent and unused if Laravel doesn't call it).

## Testing strategy

### Bridge

**Unit (`LabelEnumerationServiceTests`):**
- Maps a `PSObject` with id/name/color to a `BridgeLabel` correctly.
- A label with `ParentId` set produces a flat list with `parentId` populated (not nested) — matches CSV mapper today.
- `Disabled = "True"` maps to `isActive: false`.
- Connect-IPPSSession failure (runner returns error stream) → throws `BridgeUpstreamException`.
- Cache hit within TTL returns cached result without invoking the runner.
- Cache miss after TTL re-invokes the runner.

**Integration (`LabelEndpointTests`):**
- `GET /v1/labels` without secret → 401.
- With valid secret + mocked `LabelEnumerationService` → 200 + JSON in expected shape.
- Service throws → 502 with classified error body.

**No real-PS integration test in CI.** Manual smoke during deploy.

### Laravel

**Sync-chain tests (`SensitivityLabelServiceTest`):**
- Graph success → sync stores labels with `source: 'graph'`; bridge is not called (assert via `Http::assertNothingSent` to bridge URL).
- Graph failure + bridge success (200, valid labels) → sync stores labels with `source: 'bridge'`.
- Graph failure + bridge 200 with empty array → no labels stored, falls through to stub path.
- Graph failure + bridge 502 → caught, logged, stub path runs.
- Graph failure + bridge 401 → caught with `BridgeConfigException`-class log entry, stub path runs.

**BridgeClient test:**
- `getLabels()` happy path returns array.
- 401 → `BridgeConfigException`.
- 404 → `BridgeSiteNotFoundException` (or whatever the existing classifier emits).
- 5xx → upstream exception class.

**Tests deleted:**
- `CompliancePowerShellServiceTest.php` (entire file).
- Any `SensitivityLabelServiceTest` cases that fake-storage CSVs.

### Manual smoke test (documented in the plan)

- After bridge ship: `curl -H "X-Bridge-Secret: ..." http://127.0.0.1:5300/v1/labels`.
- After Laravel ship: `php artisan sync:sensitivity-labels`, verify "(via bridge)" in output and that 18 labels (or however many `Get-Label` currently returns in the tenant) are stored.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| `ExchangeOnlineManagement` module update breaks `Connect-IPPSSession` cert flow | Pin the module version in the installer step (`Install-Module -RequiredVersion`); document the tested-known-good version |
| `Microsoft.PowerShell.SDK` version drift vs the bridge's .NET version | Pin to a 7.4.x release that targets `net8.0` (PowerShell SDK lags .NET majors); verify it loads under `net9.0` host. If incompatible, target the bridge to `net8.0` for the PS-hosted assembly only — though this is unlikely needed |
| Compliance role grant takes time / requires admin | Deploy bridge update behind a feature flag if necessary; or delay step 2 until role is verified |
| In-process PS runspace memory leak | Each request creates and disposes a fresh runspace; cache prevents per-sync runspace churn (one runspace per 5-min window) |
| Get-Label returns subtly different fields in GCC High vs Commercial | Field-mapping in the bridge's DTO mapper is forgiving — uses `??` defaults and tolerates missing `Settings` entries, matching how `mapCsvRow()` handles the same source today |

## Out of scope (deferred)

- Migration to MIP SDK (not viable, see Background).
- Bridge endpoint for label *policies* (`Get-LabelPolicy`). Today's `SensitivityLabelService::syncLabelPolicies()` uses Graph for this; if it ever needs a non-Graph fallback, that's a separate spec. Currently returns 0 in GCC High and that's acceptable.
- Push-based label sync (webhook from Compliance Center → bridge → Laravel). Not how the system works today; out of scope.
