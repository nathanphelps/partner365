# Sensitivity-Label Enumeration via Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move tenant sensitivity-label enumeration off the Laravel host and into the bridge, replacing the CSV fallback and the Laravel-side PowerShell path with a new `GET /v1/labels` endpoint that runs `Get-Label` via `Microsoft.PowerShell.SDK` in-process.

**Architecture:** Bridge gains an `IPowerShellRunner` abstraction over PS in-process invocation, a `LabelEnumerationService` that maps `PSObject` results to a typed DTO with a 5-minute in-memory cache, and a `GET /v1/labels` minimal-API endpoint that goes through the existing `SharedSecretMiddleware`. Laravel's `BridgeClient` gains a `getLabels()` method, and `SensitivityLabelService::fetchLabelsWithFallback()` is rewritten with a Graph → Bridge → Stubs chain. Labels-only methods inside `CompliancePowerShellService` are deleted; policy-related methods remain because policy-fetching still uses them.

**Tech Stack:** .NET 9 minimal-API + WebApplicationFactory (bridge), `Microsoft.PowerShell.SDK` 7.4.x for in-process PS hosting, `ExchangeOnlineManagement` PowerShell module on the host, Moq + xUnit for bridge tests; Laravel 12 + Pest 4 + `Http::fake()` for Laravel tests.

**Spec:** `docs/superpowers/specs/2026-04-25-sensitivity-label-bridge-enumeration-design.md`

---

## File Structure

**Bridge files to create:**
- `bridge/Partner365.Bridge/Services/IPowerShellRunner.cs` — interface for PS invocation
- `bridge/Partner365.Bridge/Services/PowerShellSdkRunner.cs` — production impl (Microsoft.PowerShell.SDK)
- `bridge/Partner365.Bridge/Services/LabelEnumerationService.cs` — caching + PSObject→DTO mapping
- `bridge/Partner365.Bridge/Models/BridgeLabel.cs` — single label DTO
- `bridge/Partner365.Bridge/Models/LabelsResponse.cs` — top-level response shape
- `bridge/Partner365.Bridge.Tests/LabelEnumerationServiceTests.cs` — unit tests with a fake `IPowerShellRunner`
- `bridge/Partner365.Bridge.Tests/LabelEndpointTests.cs` — integration tests via `BridgeFactory`

**Bridge files to modify:**
- `bridge/Partner365.Bridge/Partner365.Bridge.csproj` — add `Microsoft.PowerShell.SDK` package
- `bridge/Partner365.Bridge/Program.cs` — register services + `GET /v1/labels` endpoint
- `bridge/Partner365.Bridge.Tests/BridgeFactory.cs` — replace `ILabelEnumerationService` with a mock
- `bridge/windows/README.md` — add `ExchangeOnlineManagement` install + AAD permissions
- `bridge/windows/Install-PartnerBridge.ps1` — add `Install-Module ExchangeOnlineManagement` step

**Laravel files to modify:**
- `app/Services/BridgeClient.php` — add `getLabels()` method
- `app/Services/SensitivityLabelService.php` — rewrite `fetchLabelsWithFallback()`
- `app/Services/CompliancePowerShellService.php` — delete labels-only methods
- `config/graph.php` — remove `labels_csv_path` key
- `.env.example` — remove `COMPLIANCE_LABELS_CSV_PATH`
- `tests/Feature/Services/SensitivityLabelServiceTest.php` — replace label-source tests with bridge-path tests
- `tests/Feature/Services/CompliancePowerShellServiceTest.php` — delete tests for removed methods
- `tests/Feature/Services/BridgeClientTest.php` (or create if missing) — add `getLabels()` cases

**Laravel files to create:**
- `database/migrations/2026_04_25_000000_remove_labels_csv_path_setting.php` — remove the dead settings row

**Laravel files to delete:**
- `tmp/Labels.csv` — fixture no longer used

---

## Task 1: Establish a clean baseline

**Files:** none modified.

- [ ] **Step 1: Confirm git tree clean of relevant files**

Run: `git status --short`
Expected: only `.claude/settings.local.json` (or nothing). If `bridge/`, `app/Services/SensitivityLabelService.php`, or `app/Services/CompliancePowerShellService.php` show changes, stop and report.

- [ ] **Step 2: Build the bridge**

Run: `dotnet build /c/GitHub/partner365/bridge/Partner365.Bridge.sln`
Expected: PASS, no errors.

- [ ] **Step 3: Run bridge tests**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --nologo`
Expected: PASS, total count noted (this becomes the "before" count to ensure no test loss after refactor).

- [ ] **Step 4: Run Laravel sweep + sensitivity controller tests**

Run: `php artisan test --compact --filter='SensitivityLabel|Sweep'`
Expected: PASS. Records the targeted-test baseline; full Pest suite has 66 known unrelated failures (Graph live-call tests).

- [ ] **Step 5: Run frontend type/lint/build**

Run: `npm run types:check && npm run lint && npm run build`
Expected: all PASS.

- [ ] **Step 6: Note baselines**

If steps 2–5 all pass, baseline is clean. If any fails, report and stop.

---

## Task 2: Bridge — `BridgeLabel` and `LabelsResponse` DTOs

**Files:**
- Create: `bridge/Partner365.Bridge/Models/BridgeLabel.cs`
- Create: `bridge/Partner365.Bridge/Models/LabelsResponse.cs`

These are pure data records — no behavior, no tests of their own (they're tested through `LabelEnumerationServiceTests` and `LabelEndpointTests` later).

The DTO shape mirrors what `SensitivityLabelService::parseLabel()` consumes today (see `app/Services/SensitivityLabelService.php:466-503`): nested `parent: {id}`, lowercase `contentFormats`, and a `protectionSettings` sub-object with `encryptionEnabled`/`watermarkEnabled`/`headerEnabled`/`footerEnabled`. With `JsonNamingPolicy.CamelCase` already configured in `Program.cs:127`, the serialized JSON drops in directly to the existing parser without translation.

- [ ] **Step 1: Create `BridgeLabel.cs`**

```csharp
namespace Partner365.Bridge.Models;

public sealed record BridgeLabel(
    string Id,
    string Name,
    string? Description,
    string? Color,
    string? Tooltip,
    int Priority,
    bool IsActive,
    BridgeLabelParent? Parent,
    IReadOnlyList<string> ContentFormats,
    BridgeProtectionSettings ProtectionSettings);

public sealed record BridgeLabelParent(string Id);

public sealed record BridgeProtectionSettings(
    bool EncryptionEnabled,
    bool WatermarkEnabled,
    bool HeaderEnabled,
    bool FooterEnabled);
```

- [ ] **Step 2: Create `LabelsResponse.cs`**

```csharp
namespace Partner365.Bridge.Models;

public sealed record LabelsResponse(
    string Source,
    DateTimeOffset FetchedAt,
    IReadOnlyList<BridgeLabel> Labels);
```

- [ ] **Step 3: Build to confirm no compile errors**

Run: `dotnet build /c/GitHub/partner365/bridge/Partner365.Bridge.sln`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add bridge/Partner365.Bridge/Models/BridgeLabel.cs bridge/Partner365.Bridge/Models/LabelsResponse.cs
git commit -m "$(cat <<'EOF'
feat(bridge): add label DTOs for upcoming /v1/labels endpoint

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Bridge — `IPowerShellRunner` interface

**Files:**
- Create: `bridge/Partner365.Bridge/Services/IPowerShellRunner.cs`

The interface is the seam that lets `LabelEnumerationServiceTests` inject a fake. The production impl comes in Task 5.

- [ ] **Step 1: Create the interface**

```csharp
using System.Management.Automation;

namespace Partner365.Bridge.Services;

/// <summary>
/// Abstracts an in-process PowerShell invocation. Production implementation
/// hosts PowerShell via Microsoft.PowerShell.SDK; tests substitute a fake that
/// returns canned PSObject collections so unit tests do not depend on a real
/// PowerShell host or network access to Exchange Online.
/// </summary>
public interface IPowerShellRunner
{
    /// <summary>
    /// Runs the given pipeline and returns its result objects. The
    /// implementation is responsible for connecting to Exchange Online (or
    /// any other dependency) before invoking <paramref name="command"/>.
    /// Implementations should throw on errors written to the runspace's
    /// error stream so callers do not silently see partial results.
    /// </summary>
    Task<IReadOnlyList<PSObject>> InvokeAsync(
        string command,
        IDictionary<string, object>? parameters = null,
        CancellationToken cancellationToken = default);
}
```

- [ ] **Step 2: Add System.Management.Automation reference (only if build fails)**

The interface uses `PSObject` from `System.Management.Automation`. That assembly comes with `Microsoft.PowerShell.SDK`, which we add in Task 5. For now this file may not compile alone; that's fine — Task 5 wires it up. Skip this step's commit until Task 5 lands the package.

- [ ] **Step 3: Defer commit**

The file lives in the working tree but compiles only after Task 5 adds the package. Hold off on committing this task in isolation; commit it alongside Task 5.

---

## Task 4: Bridge — `LabelEnumerationService` (TDD, fake runner)

**Files:**
- Create: `bridge/Partner365.Bridge/Services/LabelEnumerationService.cs`
- Test: `bridge/Partner365.Bridge.Tests/LabelEnumerationServiceTests.cs`

This is the heart of the change: PSObject mapping + caching. We TDD it with a fake runner so we don't need a real PS host yet.

- [ ] **Step 1: Write the failing tests first**

Create `bridge/Partner365.Bridge.Tests/LabelEnumerationServiceTests.cs`:

```csharp
using System.Management.Automation;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Time.Testing;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LabelEnumerationServiceTests
{
    private static PSObject MakeLabel(
        string id = "00000000-0000-0000-0000-000000000001",
        string name = "Confidential",
        string? parentId = null,
        string disabled = "False",
        string contentType = "File, Email",
        string settings = "[color, #4472C4] [isparent, False]",
        string labelActions = "[]")
    {
        var pso = new PSObject();
        pso.Properties.Add(new PSNoteProperty("ImmutableId", id));
        pso.Properties.Add(new PSNoteProperty("DisplayName", name));
        pso.Properties.Add(new PSNoteProperty("Comment", "desc"));
        pso.Properties.Add(new PSNoteProperty("Tooltip", "tip"));
        pso.Properties.Add(new PSNoteProperty("Priority", 5));
        pso.Properties.Add(new PSNoteProperty("ParentId", parentId));
        pso.Properties.Add(new PSNoteProperty("Disabled", disabled));
        pso.Properties.Add(new PSNoteProperty("ContentType", contentType));
        pso.Properties.Add(new PSNoteProperty("Settings", settings));
        pso.Properties.Add(new PSNoteProperty("LabelActions", labelActions));
        return pso;
    }

    private sealed class FakeRunner : IPowerShellRunner
    {
        public int InvokeCount { get; private set; }
        public Func<IReadOnlyList<PSObject>>? Result { get; set; }
        public Exception? Throw { get; set; }

        public Task<IReadOnlyList<PSObject>> InvokeAsync(string command, IDictionary<string, object>? parameters = null, CancellationToken cancellationToken = default)
        {
            InvokeCount++;
            if (Throw is not null) throw Throw;
            return Task.FromResult(Result?.Invoke() ?? Array.Empty<PSObject>());
        }
    }

    [Fact]
    public async Task Maps_basic_label_fields()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.Single(response.Labels);
        var label = response.Labels[0];
        Assert.Equal("00000000-0000-0000-0000-000000000001", label.Id);
        Assert.Equal("Confidential", label.Name);
        Assert.Equal("#4472C4", label.Color);
        Assert.Equal(5, label.Priority);
        Assert.Null(label.Parent);
        Assert.True(label.IsActive);
        Assert.Equal(new[] { "file", "email" }, label.ContentFormats);
    }

    [Fact]
    public async Task Maps_parent_id_to_nested_object()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel(parentId: "PARENT-GUID") } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.NotNull(response.Labels[0].Parent);
        Assert.Equal("PARENT-GUID", response.Labels[0].Parent!.Id);
    }

    [Fact]
    public async Task Disabled_True_yields_inactive()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel(disabled: "True") } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.False(response.Labels[0].IsActive);
    }

    [Fact]
    public async Task Encryption_action_sets_encryptionEnabled_true()
    {
        var runner = new FakeRunner
        {
            Result = () => new[] { MakeLabel(labelActions: "[{\"Type\":\"encrypt\"}]") },
        };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.True(response.Labels[0].ProtectionSettings.EncryptionEnabled);
        Assert.False(response.Labels[0].ProtectionSettings.WatermarkEnabled);
    }

    [Fact]
    public async Task UnifiedGroup_content_type_maps_to_group()
    {
        var runner = new FakeRunner
        {
            Result = () => new[] { MakeLabel(contentType: "Site, UnifiedGroup") },
        };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.Equal(new[] { "site", "group" }, response.Labels[0].ContentFormats);
    }

    [Fact]
    public async Task Cache_hit_within_ttl_does_not_reinvoke_runner()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await sut.GetLabelsAsync(default);
        clock.Advance(TimeSpan.FromMinutes(4));
        await sut.GetLabelsAsync(default);

        Assert.Equal(1, runner.InvokeCount);
    }

    [Fact]
    public async Task Cache_miss_after_ttl_reinvokes_runner()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await sut.GetLabelsAsync(default);
        clock.Advance(TimeSpan.FromMinutes(6));
        await sut.GetLabelsAsync(default);

        Assert.Equal(2, runner.InvokeCount);
    }

    [Fact]
    public async Task Runner_exception_propagates_and_does_not_cache()
    {
        var runner = new FakeRunner { Throw = new InvalidOperationException("Connect-IPPSSession failed") };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await Assert.ThrowsAsync<InvalidOperationException>(() => sut.GetLabelsAsync(default));
        runner.Throw = null;
        runner.Result = () => new[] { MakeLabel() };

        var response = await sut.GetLabelsAsync(default);

        Assert.Single(response.Labels);
        Assert.Equal(2, runner.InvokeCount);
    }
}
```

- [ ] **Step 2: Add `Microsoft.Extensions.TimeProvider.Testing` to test project (if not already present)**

```bash
dotnet add /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj package Microsoft.Extensions.TimeProvider.Testing
```

Expected: package added. (`FakeTimeProvider` lives there.)

- [ ] **Step 3: Run tests to verify they fail**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --filter "FullyQualifiedName~LabelEnumerationServiceTests" --nologo`
Expected: FAIL — `LabelEnumerationService` and/or `IPowerShellRunner` don't exist yet (compile error).

- [ ] **Step 4: Create `LabelEnumerationService.cs`**

Create `bridge/Partner365.Bridge/Services/LabelEnumerationService.cs`:

```csharp
using System.Management.Automation;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Logging;
using Partner365.Bridge.Models;

namespace Partner365.Bridge.Services;

public sealed class LabelEnumerationService
{
    private static readonly TimeSpan CacheTtl = TimeSpan.FromMinutes(5);

    private readonly IPowerShellRunner _runner;
    private readonly TimeProvider _clock;
    private readonly ILogger<LabelEnumerationService> _log;
    private readonly SemaphoreSlim _gate = new(1, 1);

    private LabelsResponse? _cached;
    private DateTimeOffset _cachedAt;

    public LabelEnumerationService(
        IPowerShellRunner runner,
        TimeProvider clock,
        ILogger<LabelEnumerationService> log)
    {
        _runner = runner;
        _clock = clock;
        _log = log;
    }

    public async Task<LabelsResponse> GetLabelsAsync(CancellationToken ct)
    {
        var now = _clock.GetUtcNow();
        if (_cached is not null && now - _cachedAt < CacheTtl)
        {
            _log.LogDebug("Label cache hit (age={Age}).", now - _cachedAt);
            return _cached;
        }

        await _gate.WaitAsync(ct);
        try
        {
            now = _clock.GetUtcNow();
            if (_cached is not null && now - _cachedAt < CacheTtl)
            {
                return _cached;
            }

            var raw = await _runner.InvokeAsync(
                "Get-Label -IncludeDetailedLabelActions",
                parameters: null,
                cancellationToken: ct);

            var mapped = raw.Select(MapLabel).ToList();
            var response = new LabelsResponse(
                Source: "powershell",
                FetchedAt: now,
                Labels: mapped);

            _cached = response;
            _cachedAt = now;
            return response;
        }
        finally
        {
            _gate.Release();
        }
    }

    private static BridgeLabel MapLabel(PSObject pso)
    {
        string Get(string name) => pso.Properties[name]?.Value?.ToString() ?? string.Empty;
        string? GetOrNull(string name)
        {
            var s = pso.Properties[name]?.Value?.ToString();
            return string.IsNullOrEmpty(s) ? null : s;
        }
        int GetInt(string name)
        {
            var v = pso.Properties[name]?.Value;
            return v switch
            {
                int i => i,
                long l => (int)l,
                string s when int.TryParse(s, out var parsed) => parsed,
                _ => 0,
            };
        }

        var id = Get("ImmutableId");
        if (string.IsNullOrEmpty(id))
        {
            id = Get("Guid");
        }

        var disabled = Get("Disabled");
        var isActive = !string.Equals(disabled, "True", StringComparison.OrdinalIgnoreCase);

        var color = ExtractSettingsValue(Get("Settings"), "color");
        var contentFormats = ParseContentType(Get("ContentType"));
        var protection = ParseLabelActions(Get("LabelActions"));

        var parentId = GetOrNull("ParentId");
        var parent = parentId is null ? null : new BridgeLabelParent(parentId);

        return new BridgeLabel(
            Id: id,
            Name: Get("DisplayName"),
            Description: GetOrNull("Comment"),
            Color: color,
            Tooltip: GetOrNull("Tooltip"),
            Priority: GetInt("Priority"),
            IsActive: isActive,
            Parent: parent,
            ContentFormats: contentFormats,
            ProtectionSettings: protection);
    }

    private static string? ExtractSettingsValue(string settings, string key)
    {
        if (string.IsNullOrEmpty(settings))
        {
            return null;
        }

        var match = SettingsValuePattern(key).Match(settings);
        return match.Success ? match.Groups[1].Value : null;
    }

    private static IReadOnlyList<string> ParseContentType(string value)
    {
        // Mirrors CompliancePowerShellService::parseContentType: lowercase
        // substring matching to {file, email, site, group} where
        // "UnifiedGroup" (or just "Group") collapses to "group".
        if (string.IsNullOrWhiteSpace(value))
        {
            return Array.Empty<string>();
        }

        var lower = value.ToLowerInvariant();
        var formats = new List<string>();
        if (lower.Contains("file")) formats.Add("file");
        if (lower.Contains("email")) formats.Add("email");
        if (lower.Contains("site")) formats.Add("site");
        if (lower.Contains("unifiedgroup") || lower.Contains("group")) formats.Add("group");
        return formats;
    }

    private static BridgeProtectionSettings ParseLabelActions(string actionsJson)
    {
        if (string.IsNullOrWhiteSpace(actionsJson) || actionsJson == "[]")
        {
            return new BridgeProtectionSettings(false, false, false, false);
        }

        try
        {
            using var doc = JsonDocument.Parse(actionsJson);
            bool encryption = false, watermark = false, header = false, footer = false;
            foreach (var element in doc.RootElement.EnumerateArray())
            {
                if (!element.TryGetProperty("Type", out var typeProp))
                {
                    continue;
                }
                var type = typeProp.GetString()?.ToLowerInvariant() ?? string.Empty;
                if (type.Contains("encrypt")) encryption = true;
                if (type.Contains("watermark")) watermark = true;
                if (type.Contains("header")) header = true;
                if (type.Contains("footer")) footer = true;
            }
            return new BridgeProtectionSettings(encryption, watermark, header, footer);
        }
        catch (JsonException)
        {
            return new BridgeProtectionSettings(false, false, false, false);
        }
    }

    // Cache compiled regexes per key. Keys are static strings (color, isparent, …),
    // so the dictionary stays small and prevents recompiling per call.
    private static readonly Dictionary<string, Regex> _settingsRegexCache = new();

    private static Regex SettingsValuePattern(string key)
    {
        if (_settingsRegexCache.TryGetValue(key, out var cached))
        {
            return cached;
        }
        var pattern = new Regex(
            $@"\[\s*{Regex.Escape(key)}\s*,\s*([^\]]+)\s*\]",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);
        _settingsRegexCache[key] = pattern;
        return pattern;
    }
}
```

- [ ] **Step 5: Create the `IPowerShellRunner.cs` file from Task 3**

Use the exact content from Task 3 Step 1.

- [ ] **Step 6: Run tests to verify all pass**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --filter "FullyQualifiedName~LabelEnumerationServiceTests" --nologo`
Expected: 8 PASS.

- [ ] **Step 7: Run full bridge test suite to confirm no regressions**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --nologo`
Expected: PASS, total count = baseline + 8 new tests.

- [ ] **Step 8: Commit**

```bash
git add bridge/Partner365.Bridge/Services/IPowerShellRunner.cs bridge/Partner365.Bridge/Services/LabelEnumerationService.cs bridge/Partner365.Bridge.Tests/LabelEnumerationServiceTests.cs bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj
git commit -m "$(cat <<'EOF'
feat(bridge): add LabelEnumerationService with PSObject->DTO mapping + cache

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Bridge — `PowerShellSdkRunner` production implementation

**Files:**
- Create: `bridge/Partner365.Bridge/Services/PowerShellSdkRunner.cs`
- Modify: `bridge/Partner365.Bridge/Partner365.Bridge.csproj`

This is the only piece that requires a real PS host to fully exercise. Unit-testing it means inspecting runspace state, which is brittle. We rely on Task 6's WebApplicationFactory tests (with the runner mocked) plus a manual smoke test.

- [ ] **Step 1: Add `Microsoft.PowerShell.SDK` package to bridge csproj**

```bash
dotnet add /c/GitHub/partner365/bridge/Partner365.Bridge/Partner365.Bridge.csproj package Microsoft.PowerShell.SDK --version 7.4.6
```

Expected: package added without conflicts. If a target-framework warning appears (PowerShell.SDK targets `net8.0`), it is acceptable — `net9.0` is forwards-compatible with `net8.0` libraries.

- [ ] **Step 2: Verify the bridge still builds**

Run: `dotnet build /c/GitHub/partner365/bridge/Partner365.Bridge.sln`
Expected: PASS.

- [ ] **Step 3: Create the production runner**

Create `bridge/Partner365.Bridge/Services/PowerShellSdkRunner.cs`:

```csharp
using System.Management.Automation;
using System.Management.Automation.Runspaces;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Partner365.Bridge.Services;

/// <summary>
/// Hosts PowerShell in-process via Microsoft.PowerShell.SDK to call
/// Get-Label against Exchange Online's Compliance endpoint. A fresh
/// runspace is created per invocation to avoid long-lived runspace state
/// leaking between calls; the LabelEnumerationService cache prevents
/// per-sync churn.
/// </summary>
public sealed class PowerShellSdkRunner : IPowerShellRunner
{
    private readonly BridgeOptions _opts;
    private readonly ILogger<PowerShellSdkRunner> _log;

    public PowerShellSdkRunner(IOptions<BridgeOptions> opts, ILogger<PowerShellSdkRunner> log)
    {
        _opts = opts.Value;
        _log = log;
    }

    public async Task<IReadOnlyList<PSObject>> InvokeAsync(
        string command,
        IDictionary<string, object>? parameters = null,
        CancellationToken cancellationToken = default)
    {
        return await Task.Run(() => InvokeCore(command, parameters, cancellationToken), cancellationToken);
    }

    private IReadOnlyList<PSObject> InvokeCore(string command, IDictionary<string, object>? parameters, CancellationToken ct)
    {
        var iss = InitialSessionState.CreateDefault2();
        using var runspace = RunspaceFactory.CreateRunspace(iss);
        runspace.Open();

        using var ps = PowerShell.Create();
        ps.Runspace = runspace;
        ct.Register(() => ps.Stop());

        ImportExchangeOnlineModule(ps);
        ConnectIPPSSession(ps);

        ps.Commands.Clear();
        ps.AddScript(command);
        if (parameters is not null)
        {
            foreach (var kv in parameters)
            {
                ps.AddParameter(kv.Key, kv.Value);
            }
        }

        var results = ps.Invoke();
        ThrowIfErrors(ps, command);
        return results;
    }

    private void ImportExchangeOnlineModule(PowerShell ps)
    {
        ps.Commands.Clear();
        ps.AddCommand("Import-Module").AddParameter("Name", "ExchangeOnlineManagement").AddParameter("ErrorAction", "Stop");
        _ = ps.Invoke();
        ThrowIfErrors(ps, "Import-Module ExchangeOnlineManagement");
    }

    private void ConnectIPPSSession(PowerShell ps)
    {
        var environmentName = _opts.CloudEnvironment.ToLowerInvariant().Replace('_', '-') switch
        {
            "gcc-high" => "O365USGovGCCHigh",
            "commercial" => "O365Default",
            var x => throw new InvalidOperationException(
                $"Unsupported cloud environment '{x}' for Connect-IPPSSession. Expected 'commercial' or 'gcc-high'."),
        };

        var orgFqdn = _opts.AdminSiteUrl
            .Replace("https://", string.Empty, StringComparison.OrdinalIgnoreCase)
            .TrimEnd('/');
        if (orgFqdn.Contains("-admin."))
        {
            orgFqdn = orgFqdn.Replace("-admin.", ".", StringComparison.OrdinalIgnoreCase);
        }

        ps.Commands.Clear();
        ps.AddCommand("Connect-IPPSSession")
            .AddParameter("AppId", _opts.ClientId)
            .AddParameter("Organization", orgFqdn)
            .AddParameter("CertificateThumbprint", _opts.CertThumbprint ?? throw new InvalidOperationException(
                "Bridge:CertThumbprint is required for label enumeration. Set BRIDGE_CERT_THUMBPRINT pointing to the cert in LocalMachine\\My."))
            .AddParameter("ExchangeEnvironmentName", environmentName)
            .AddParameter("ShowBanner", false)
            .AddParameter("ErrorAction", "Stop");

        _ = ps.Invoke();
        ThrowIfErrors(ps, "Connect-IPPSSession");
    }

    private static void ThrowIfErrors(PowerShell ps, string context)
    {
        if (!ps.HadErrors)
        {
            return;
        }

        var firstError = ps.Streams.Error.Count > 0
            ? ps.Streams.Error[0].ToString()
            : "PowerShell pipeline reported errors but the error stream was empty.";

        throw new InvalidOperationException($"{context} failed: {firstError}");
    }
}
```

- [ ] **Step 4: Build the bridge**

Run: `dotnet build /c/GitHub/partner365/bridge/Partner365.Bridge.sln`
Expected: PASS. The runner compiles and links against the new package.

- [ ] **Step 5: Confirm bridge tests still pass**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --nologo`
Expected: PASS (same count as Task 4).

- [ ] **Step 6: Commit**

```bash
git add bridge/Partner365.Bridge/Services/PowerShellSdkRunner.cs bridge/Partner365.Bridge/Partner365.Bridge.csproj
git commit -m "$(cat <<'EOF'
feat(bridge): add PowerShellSdkRunner for in-process Get-Label invocation

Production IPowerShellRunner implementation. Uses Microsoft.PowerShell.SDK
to host PowerShell in-process; opens a fresh runspace per call, imports
ExchangeOnlineManagement, calls Connect-IPPSSession with cert credentials
and the cloud-environment-derived ExchangeEnvironmentName, then invokes
the requested command. Errors written to the runspace's error stream
throw InvalidOperationException so callers see classified failures
rather than empty results.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Bridge — `GET /v1/labels` endpoint + DI + integration tests

**Files:**
- Modify: `bridge/Partner365.Bridge/Program.cs`
- Modify: `bridge/Partner365.Bridge.Tests/BridgeFactory.cs`
- Create: `bridge/Partner365.Bridge.Tests/LabelEndpointTests.cs`

- [ ] **Step 1: Write the failing endpoint tests**

Create `bridge/Partner365.Bridge.Tests/LabelEndpointTests.cs`:

```csharp
using System.Net;
using System.Net.Http.Json;
using Microsoft.Extensions.DependencyInjection;
using Moq;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LabelEndpointTests : IClassFixture<BridgeFactory>
{
    private readonly BridgeFactory _factory;

    public LabelEndpointTests(BridgeFactory factory) => _factory = factory;

    [Fact]
    public async Task Without_secret_returns_401()
    {
        using var client = _factory.CreateClient();
        var response = await client.GetAsync("/v1/labels");
        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
    }

    [Fact]
    public async Task With_secret_returns_labels()
    {
        var labels = new LabelsResponse(
            Source: "powershell",
            FetchedAt: DateTimeOffset.UtcNow,
            Labels: new[]
            {
                new BridgeLabel(
                    Id: "label-1",
                    Name: "Confidential",
                    Description: null,
                    Color: "#4472C4",
                    Tooltip: null,
                    Priority: 5,
                    IsActive: true,
                    Parent: null,
                    ContentFormats: new[] { "file", "email" },
                    ProtectionSettings: new BridgeProtectionSettings(
                        EncryptionEnabled: true,
                        WatermarkEnabled: false,
                        HeaderEnabled: false,
                        FooterEnabled: false)),
            });
        _factory.LabelService.Setup(s => s.GetLabelsAsync(It.IsAny<CancellationToken>()))
            .ReturnsAsync(labels);

        using var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        var response = await client.GetAsync("/v1/labels");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await response.Content.ReadFromJsonAsync<LabelsResponse>();
        Assert.NotNull(body);
        Assert.Single(body!.Labels);
        Assert.Equal("Confidential", body.Labels[0].Name);
        Assert.Equal(new[] { "file", "email" }, body.Labels[0].ContentFormats);
        Assert.True(body.Labels[0].ProtectionSettings.EncryptionEnabled);
    }

    [Fact]
    public async Task Service_throws_returns_502()
    {
        _factory.LabelService.Setup(s => s.GetLabelsAsync(It.IsAny<CancellationToken>()))
            .ThrowsAsync(new InvalidOperationException("Connect-IPPSSession failed"));

        using var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        var response = await client.GetAsync("/v1/labels");

        Assert.Equal(HttpStatusCode.BadGateway, response.StatusCode);
    }
}
```

- [ ] **Step 2: Introduce a thin interface `ILabelEnumerationService`**

The endpoint test mocks `ILabelEnumerationService`. Add the interface so the factory can inject a Moq.

Modify `bridge/Partner365.Bridge/Services/LabelEnumerationService.cs` to add an interface alongside the class:

At the top of the file, after the namespace declaration, add:

```csharp
public interface ILabelEnumerationService
{
    Task<LabelsResponse> GetLabelsAsync(CancellationToken ct);
}
```

Change the class declaration:

```csharp
public sealed class LabelEnumerationService : ILabelEnumerationService
```

- [ ] **Step 3: Update `BridgeFactory` to expose a Moq for the new service**

Modify `bridge/Partner365.Bridge.Tests/BridgeFactory.cs`. Add a property and DI override:

After the existing `Ops` property:

```csharp
public Mock<ILabelEnumerationService> LabelService { get; } = new();
```

In `ConfigureWebHost`, after the existing `services.RemoveAll<ICsomOperations>()` + `services.AddSingleton(Ops.Object)` lines, add:

```csharp
services.RemoveAll<ILabelEnumerationService>();
services.AddSingleton(LabelService.Object);
services.RemoveAll<IPowerShellRunner>();
services.AddSingleton(Mock.Of<IPowerShellRunner>());
```

(The `IPowerShellRunner` removal prevents Program.cs from trying to construct the real `PowerShellSdkRunner` during tests, which would attempt to call `Connect-IPPSSession`.)

Add the using directive at the top of `BridgeFactory.cs`:

```csharp
using Partner365.Bridge.Models;
```

- [ ] **Step 4: Run the new tests to verify they fail (endpoint and DI not wired)**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --filter "FullyQualifiedName~LabelEndpointTests" --nologo`
Expected: FAIL — endpoint not registered, returns 404 instead of 200/401/502.

- [ ] **Step 5: Wire DI registrations in `Program.cs`**

Open `bridge/Partner365.Bridge/Program.cs`. Find the line `builder.Services.AddSingleton<SharePointCsomService>();` (around line 123). Immediately after it, add:

```csharp
builder.Services.AddSingleton<TimeProvider>(_ => TimeProvider.System);
builder.Services.AddSingleton<IPowerShellRunner, PowerShellSdkRunner>();
builder.Services.AddSingleton<ILabelEnumerationService, LabelEnumerationService>();
```

- [ ] **Step 6: Map the endpoint in `Program.cs`**

Find `app.MapPost("/v1/sites/label:read", …)` (around line 199–242). Immediately after that block (after the closing `});`), add:

```csharp
app.MapGet("/v1/labels", async (
    ILabelEnumerationService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var response = await svc.GetLabelsAsync(ct);
        log.LogInformation("{RequestId} list-labels count={Count} source={Source}",
            requestId, response.Labels.Count, response.Source);
        return Results.Ok(response);
    }
    catch (OperationCanceledException)
    {
        throw;
    }
    catch (Exception ex)
    {
        return ClassifyServerError(ex, log, requestId, "list-labels", siteUrl: "(n/a)");
    }
});
```

- [ ] **Step 7: Run the endpoint tests**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --filter "FullyQualifiedName~LabelEndpointTests" --nologo`
Expected: 3 PASS.

- [ ] **Step 8: Run full bridge test suite**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --nologo`
Expected: PASS, total count = baseline + 7 (LabelEnumerationServiceTests) + 3 (LabelEndpointTests).

- [ ] **Step 9: Commit**

```bash
git add bridge/Partner365.Bridge/Program.cs bridge/Partner365.Bridge/Services/LabelEnumerationService.cs bridge/Partner365.Bridge.Tests/BridgeFactory.cs bridge/Partner365.Bridge.Tests/LabelEndpointTests.cs
git commit -m "$(cat <<'EOF'
feat(bridge): wire GET /v1/labels endpoint and DI registrations

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Bridge — installer and README updates

**Files:**
- Modify: `bridge/windows/README.md`
- Modify: `bridge/windows/Install-PartnerBridge.ps1`

- [ ] **Step 1: Read the current README to find the Prerequisites section**

Run: `cat /c/GitHub/partner365/bridge/windows/README.md | head -80`
Locate the "Prerequisites" or equivalent section. If it does not exist, place the new content immediately after the file's introduction.

- [ ] **Step 2: Add prerequisites entry to README**

Append to the prerequisites section of `bridge/windows/README.md`:

```markdown
### Sensitivity-label enumeration prerequisites

The bridge's `GET /v1/labels` endpoint runs `Get-Label` via Exchange Online
PowerShell in-process. One-time host setup as the bridge's service
account:

```powershell
Install-Module ExchangeOnlineManagement -Scope AllUsers -Force -RequiredVersion 3.5.1
```

The AAD app registration (the same one used for SharePoint CSOM) needs:

- Application permission `Office 365 Exchange Online → Exchange.ManageAsApp` (admin consent required)
- Directory role assignment on the service principal: `Compliance Data Administrator` (read-only) or `Compliance Administrator`

The bridge reuses its existing certificate and tenant/client config — no
new credentials.
```

- [ ] **Step 3: Add the Install-Module step to the installer script**

Open `bridge/windows/Install-PartnerBridge.ps1`. Find the section that installs prerequisites (search for `Install-Module` or `Microsoft.PowerShell` or similar bootstrap commands; if none, add the block near the top of the script's main flow).

Add this block:

```powershell
Write-Host "Ensuring ExchangeOnlineManagement module is available..." -ForegroundColor Cyan
$existing = Get-Module -ListAvailable -Name ExchangeOnlineManagement |
    Where-Object { $_.Version -ge [Version]'3.5.1' } |
    Select-Object -First 1
if (-not $existing) {
    Install-Module -Name ExchangeOnlineManagement -Scope AllUsers -Force -RequiredVersion 3.5.1
    Write-Host "  Installed ExchangeOnlineManagement 3.5.1." -ForegroundColor Green
} else {
    Write-Host "  Found ExchangeOnlineManagement $($existing.Version) (>=3.5.1) — skipping install." -ForegroundColor Green
}
```

- [ ] **Step 4: Verify the installer script still parses**

Run: `pwsh -NoProfile -Command "$null = [System.Management.Automation.Language.Parser]::ParseFile('C:\GitHub\partner365\bridge\windows\Install-PartnerBridge.ps1', [ref]$null, [ref]$null); 'OK'"`
Expected: prints `OK`.

- [ ] **Step 5: Commit**

```bash
git add bridge/windows/README.md bridge/windows/Install-PartnerBridge.ps1
git commit -m "$(cat <<'EOF'
docs(bridge): document ExchangeOnlineManagement prereq for /v1/labels

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Laravel — `BridgeClient::getLabels()` with tests

**Files:**
- Modify: `app/Services/BridgeClient.php`
- Test: `tests/Feature/Services/BridgeClientTest.php` (create if absent; otherwise add to existing)

- [ ] **Step 1: Find the existing BridgeClient test file**

Run: `ls tests/Feature/Services/ | grep -i bridge`
If `BridgeClientTest.php` exists, modify it. If not, create it.

- [ ] **Step 2: Write the failing tests**

Add (or create) `tests/Feature/Services/BridgeClientTest.php`:

```php
<?php

use App\Models\Setting;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge.test:5300');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'test-secret');
});

test('getLabels returns labels array on 200', function () {
    Http::fake([
        'bridge.test:5300/v1/labels' => Http::response([
            'source' => 'powershell',
            'fetchedAt' => '2026-04-25T13:00:00Z',
            'labels' => [
                ['id' => 'label-1', 'name' => 'Confidential', 'priority' => 5],
                ['id' => 'label-2', 'name' => 'Public', 'priority' => 1],
            ],
        ], 200),
    ]);

    $client = new BridgeClient();
    $labels = $client->getLabels();

    expect($labels)->toBeArray()->toHaveCount(2);
    expect($labels[0]['name'])->toBe('Confidential');
});

test('getLabels throws BridgeConfigException on 401', function () {
    Http::fake([
        'bridge.test:5300/v1/labels' => Http::response([
            'error' => ['code' => 'unauthorized', 'message' => 'bad secret', 'requestId' => 'r1'],
        ], 401),
    ]);

    $client = new BridgeClient();

    expect(fn () => $client->getLabels())->toThrow(BridgeConfigException::class);
});

test('getLabels throws on 502 from upstream PS failure', function () {
    Http::fake([
        'bridge.test:5300/v1/labels' => Http::response([
            'error' => ['code' => 'auth', 'message' => 'Connect-IPPSSession failed', 'requestId' => 'r2'],
        ], 502),
    ]);

    $client = new BridgeClient();

    expect(fn () => $client->getLabels())->toThrow(\App\Services\Exceptions\BridgeAuthException::class);
});

test('getLabels sends the shared secret header', function () {
    Http::fake([
        'bridge.test:5300/v1/labels' => Http::response([
            'source' => 'powershell',
            'fetchedAt' => '2026-04-25T13:00:00Z',
            'labels' => [],
        ], 200),
    ]);

    $client = new BridgeClient();
    $client->getLabels();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Bridge-Secret', 'test-secret'));
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter='BridgeClientTest'`
Expected: FAIL — `BridgeClient::getLabels()` does not exist.

- [ ] **Step 4: Add the `getLabels()` method to `BridgeClient.php`**

Open `app/Services/BridgeClient.php`. Find the `health()` method (around line 59). Immediately after it, add:

```php
public function getLabels(): array
{
    $response = $this->tryRequest(
        fn (\Illuminate\Http\Client\PendingRequest $http) => $http->get(
            $this->baseUrl().'/v1/labels',
        )
    );

    $this->throwOnError($response);

    return $response->json('labels') ?? [];
}
```

- [ ] **Step 5: Run lint + tests**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact --filter='BridgeClientTest'`
Expected: lint PASS, 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/BridgeClient.php tests/Feature/Services/BridgeClientTest.php
git commit -m "$(cat <<'EOF'
feat: add BridgeClient::getLabels() for /v1/labels endpoint

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Laravel — rewrite `fetchLabelsWithFallback()` to use the bridge

**Files:**
- Modify: `app/Services/SensitivityLabelService.php`
- Test: `tests/Feature/Services/SensitivityLabelServiceTest.php`

- [ ] **Step 1: Find the relevant test cases for label fetching**

Run: `grep -n 'csv\|powershell\|getLabels\|Tier\|fetchLabelsWithFallback' tests/Feature/Services/SensitivityLabelServiceTest.php`
Note line ranges of CSV-path tests, PS-path tests, and any other label-source tests so you know what to delete.

- [ ] **Step 2: Write new bridge-path test cases**

Add these tests to `tests/Feature/Services/SensitivityLabelServiceTest.php` (placement: alongside existing label-fetch tests). Adapt to existing setup helpers in the file:

```php
test('graph success: bridge is not called', function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge.test:5300');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'test-secret');

    Http::fake([
        // Graph endpoint succeeds with one label
        'graph.microsoft.us/*' => Http::sequence()
            ->push(['access_token' => 'tok'], 200)
            ->push(['value' => [
                ['id' => 'graph-label-1', 'name' => 'GraphLabel', 'priority' => 1, 'isActive' => true],
            ]], 200),
        'login.microsoftonline.us/*' => Http::response(['access_token' => 'tok'], 200),
        'bridge.test:5300/v1/labels' => Http::response([
            'source' => 'powershell',
            'fetchedAt' => '2026-04-25T13:00:00Z',
            'labels' => [],
        ], 200),
    ]);

    $service = app(\App\Services\SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect($result['source'])->toBe('graph');
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'bridge.test:5300/v1/labels'));
});

test('graph fail + bridge success: bridge labels are synced', function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge.test:5300');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'test-secret');

    Http::fake([
        'graph.microsoft.us/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
        'login.microsoftonline.us/*' => Http::response(['access_token' => 'tok'], 200),
        'bridge.test:5300/v1/labels' => Http::response([
            'source' => 'powershell',
            'fetchedAt' => '2026-04-25T13:00:00Z',
            'labels' => [
                [
                    'id' => 'bridge-1',
                    'name' => 'Confidential',
                    'description' => null,
                    'color' => '#4472C4',
                    'tooltip' => null,
                    'priority' => 5,
                    'isActive' => true,
                    'parent' => null,
                    'contentFormats' => ['file', 'email'],
                    'protectionSettings' => [
                        'encryptionEnabled' => true,
                        'watermarkEnabled' => false,
                        'headerEnabled' => false,
                        'footerEnabled' => false,
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = app(\App\Services\SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect($result['source'])->toBe('bridge');
    expect($result['labels_synced'])->toBe(1);
});

test('graph fail + bridge 502: falls through to stub source', function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge.test:5300');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'test-secret');

    Http::fake([
        'graph.microsoft.us/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
        'login.microsoftonline.us/*' => Http::response(['access_token' => 'tok'], 200),
        'bridge.test:5300/v1/labels' => Http::response([
            'error' => ['code' => 'auth', 'message' => 'Connect failed', 'requestId' => 'r'],
        ], 502),
    ]);

    $service = app(\App\Services\SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect($result['source'])->toBe('unavailable');
});
```

- [ ] **Step 3: Delete the existing CSV/PS tests**

In `tests/Feature/Services/SensitivityLabelServiceTest.php`, delete every test that depends on:
- `Storage::fake()` for CSV reading,
- `compliance->getCsvLabelsPath()` mocking,
- `compliance->getLabels()` (PowerShell-direct),
- `compliance->isAvailable()` returning true to drive the PS path.

Keep tests for: policy fetching, site-label syncing, partner mapping, stub creation. (These don't change.)

- [ ] **Step 4: Run new tests to verify they fail**

Run: `php artisan test --compact --filter='SensitivityLabelServiceTest'`
Expected: FAIL — `SensitivityLabelService::fetchLabelsWithFallback()` still calls CSV/PS, not the bridge.

- [ ] **Step 5: Rewrite `fetchLabelsWithFallback()`**

Open `app/Services/SensitivityLabelService.php`. Add a `BridgeClient` constructor parameter. Modify the constructor (around line 17):

```php
public function __construct(
    private MicrosoftGraphService $graph,
    private SharePointAdminService $spoAdmin,
    private CompliancePowerShellService $compliance,
    private BridgeClient $bridge,
) {}
```

Add the import at the top of the file:

```php
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeException;
```

(The `BridgeException` is the parent class of all bridge exceptions; verify the actual base class name in `app/Services/Exceptions/`. If there is no shared parent, catch `\Throwable` instead.)

Replace the `fetchLabelsWithFallback()` body (around lines 235–281) with:

```php
private function fetchLabelsWithFallback(): ?array
{
    // Tier 1: Graph API (works in commercial; not in GCC High)
    try {
        $labels = $this->fetchLabelsFromGraph();

        return ['labels' => $labels, 'source' => 'graph'];
    } catch (GraphApiException|\RuntimeException $e) {
        Log::warning("Graph API label fetch failed: {$e->getMessage()}", [
            'exception_class' => get_class($e),
        ]);
    }

    // Tier 2: Bridge (replaces CSV + Laravel-side PowerShell)
    try {
        $labels = $this->bridge->getLabels();
        if (! empty($labels)) {
            return ['labels' => $labels, 'source' => 'bridge'];
        }
        Log::warning('Bridge returned empty label list — falling through to stubs.');
    } catch (\Throwable $e) {
        Log::warning("Bridge label fetch failed: {$e->getMessage()}", [
            'exception_class' => get_class($e),
        ]);
    }

    // Tier 3: Stubs created during syncSiteLabels()
    Log::warning('No label source available — labels will be created as stubs from site data');

    return null;
}
```

- [ ] **Step 6: Verify `fetchPoliciesWithFallback()` is untouched**

Run: `grep -n 'fetchPoliciesWithFallback' app/Services/SensitivityLabelService.php`
Confirm the method body still calls `$this->compliance->getPolicies()`. Do not modify it.

- [ ] **Step 7: Run lint + tests**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact --filter='SensitivityLabelServiceTest'`
Expected: lint PASS, all `SensitivityLabelServiceTest` cases PASS (including the new bridge-path ones).

- [ ] **Step 8: Commit**

```bash
git add app/Services/SensitivityLabelService.php tests/Feature/Services/SensitivityLabelServiceTest.php
git commit -m "$(cat <<'EOF'
feat: replace CSV+PS label fallback with bridge call

Sync chain becomes Graph -> Bridge -> Stubs. CSV file path and
direct-PowerShell-on-Laravel-host path are removed. Policy fetching
still uses CompliancePowerShellService (deferred to a separate change).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Laravel — clean up dead labels code

**Files:**
- Modify: `app/Services/CompliancePowerShellService.php`
- Modify: `tests/Feature/Services/CompliancePowerShellServiceTest.php`
- Modify: `config/graph.php`
- Modify: `.env.example`
- Delete: `tmp/Labels.csv`
- Create: `database/migrations/2026_04_25_000000_remove_labels_csv_path_setting.php`

- [ ] **Step 1: Re-confirm the labels-only methods to delete**

Run: `grep -n 'public function\|private function' app/Services/CompliancePowerShellService.php`
Methods to delete:
- `getLabels` (line 25)
- `getCsvLabelsPath` (line 32)
- `parseCsvLabels` (line 43)
- `mapCsvRow` (line 75) — private
- `extractSettingsValue` (line 95) — private
- `parseLabelsOutput` (line 111)
- `mapLabel` (line 172) — private
- `parseContentType` (line 208) — private
- `parseLabelActions` (line 229) — private

Methods to KEEP:
- `isAvailable`, `getPolicies`, `parsePoliciesOutput`, `mapPolicy`, `runPowerShell`, `findPwshCommand`

- [ ] **Step 2: Delete labels-only methods from `CompliancePowerShellService.php`**

Open `app/Services/CompliancePowerShellService.php` and delete the nine methods listed above. After deletion, verify the file still compiles and the policy methods still reference only kept dependencies.

If `mapPolicy` calls any of the deleted helpers, copy the helper's logic into `mapPolicy` directly (do not keep a deleted helper alive). Run: `grep -E 'extractSettingsValue|parseContentType|parseLabelActions' app/Services/CompliancePowerShellService.php` after deletion — if anything remains, fix the policy method to inline-handle that piece.

- [ ] **Step 3: Delete labels-only test cases from `CompliancePowerShellServiceTest.php`**

Open `tests/Feature/Services/CompliancePowerShellServiceTest.php`. Delete every test that exercises:
- `getLabels()`, `getCsvLabelsPath()`, `parseCsvLabels()`, `mapCsvRow()`, `extractSettingsValue()`,
- `parseLabelsOutput()`, `mapLabel()`, `parseContentType()`, `parseLabelActions()`.

Keep tests for `isAvailable`, `getPolicies`, `parsePoliciesOutput`, `mapPolicy`, `runPowerShell`, `findPwshCommand`.

- [ ] **Step 4: Remove `labels_csv_path` from config**

Open `config/graph.php`. Delete the line:

```php
'labels_csv_path' => env('COMPLIANCE_LABELS_CSV_PATH'),
```

Keep `compliance_certificate_path` and `compliance_certificate_password` lines (still used by policy fetching).

- [ ] **Step 5: Remove `COMPLIANCE_LABELS_CSV_PATH` from `.env.example`**

Open `.env.example`. Delete the `COMPLIANCE_LABELS_CSV_PATH=...` line. Keep `COMPLIANCE_CERTIFICATE_PATH` and `COMPLIANCE_CERTIFICATE_PASSWORD`.

- [ ] **Step 6: Delete the test fixture**

```bash
git rm tmp/Labels.csv
```

- [ ] **Step 7: Generate the migration**

```bash
php artisan make:migration remove_labels_csv_path_setting --no-interaction
```

This creates a file with a timestamp prefix in `database/migrations/`. Note its actual filename.

- [ ] **Step 8: Fill in the migration**

Replace the migration body with:

```php
<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::where('group', 'graph')
            ->where('key', 'labels_csv_path')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally empty: the value was a local filesystem path that
        // varies per host. Restoring a placeholder would create a broken
        // setting.
    }
};
```

- [ ] **Step 9: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected: migration runs without error. (No-op if no row exists.)

- [ ] **Step 10: Run lint + relevant tests**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact --filter='CompliancePowerShellServiceTest|SensitivityLabelServiceTest|BridgeClientTest'`
Expected: PASS — Pest treats deleted tests as removed; remaining tests pass.

- [ ] **Step 11: Re-run sweep + sensitivity tests for safety**

Run: `php artisan test --compact --filter='SensitivityLabel|Sweep'`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add app/Services/CompliancePowerShellService.php tests/Feature/Services/CompliancePowerShellServiceTest.php config/graph.php .env.example database/migrations/*remove_labels_csv_path*.php
git rm tmp/Labels.csv
git commit -m "$(cat <<'EOF'
chore: remove labels-only CSV + PowerShell paths from Laravel

Delete getLabels/getCsvLabelsPath/parseCsvLabels/parseLabelsOutput and
their helpers from CompliancePowerShellService — labels now come from
the bridge. Drop the COMPLIANCE_LABELS_CSV_PATH config key and the
tmp/Labels.csv fixture. Migration removes the labels_csv_path settings
row if present.

Policy fetching still uses CompliancePowerShellService::getPolicies(),
so the class, COMPLIANCE_CERTIFICATE_* config, and the
ExchangeOnlineManagement module on the Laravel host all remain.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Final full-suite verification + manual smoke

**Files:** none modified.

- [ ] **Step 1: Bridge — full test suite**

Run: `dotnet test /c/GitHub/partner365/bridge/Partner365.Bridge.Tests/Partner365.Bridge.Tests.csproj --nologo`
Expected: PASS, count = baseline + 8 (label service) + 3 (endpoint) = baseline + 11.

- [ ] **Step 2: Bridge — release build**

Run: `dotnet build /c/GitHub/partner365/bridge/Partner365.Bridge.sln -c Release`
Expected: PASS.

- [ ] **Step 3: Laravel — types + lint + format + Pint**

Run: `npm run types:check && npm run lint && npx prettier --check resources/ && vendor/bin/pint --dirty --format agent`
Expected: all PASS.

- [ ] **Step 4: Laravel — sweep + sensitivity tests**

Run: `php artisan test --compact --filter='SensitivityLabel|Sweep|BridgeClient'`
Expected: PASS.

- [ ] **Step 5: Laravel — frontend production build**

Run: `npm run build`
Expected: PASS.

- [ ] **Step 6: Manual smoke against the running bridge — endpoint reachable + auth**

If the bridge is running locally and the AAD app has the Compliance role granted, run:

```bash
curl -H "X-Bridge-Secret: $(php artisan tinker --execute='echo Setting::get(\"sensitivity_sweep\",\"bridge_shared_secret\");' 2>/dev/null | tail -1)" http://127.0.0.1:5300/v1/labels
```

Expected: 200 with `{"source":"powershell", "labels":[...]}`. If the role is not yet granted, you will see a 502 with `code: "auth"` — that is the expected failure mode and Tier 3 (stubs) will run during sync.

- [ ] **Step 7: Manual smoke — full sync via the new path**

```bash
php artisan sync:sensitivity-labels
```

Expected: output reads `Labels synced: N (via bridge)` if the bridge endpoint succeeded, or `(via unavailable)` falling through to stubs if it did not. Either output is acceptable — what matters is that the command does not say `(via csv)` or `(via powershell)`, since those tiers are gone.

- [ ] **Step 8: Confirm the git log shows clean focused commits**

Run: `git log --oneline -n 12`
Expected: Tasks 2, 4, 5, 6, 7, 8, 9, 10 each as their own commit, in order.

- [ ] **Step 9: Push only when the user explicitly authorizes**

Do not run `git push` unprompted. Hand off and let the user direct push timing.

---

## Out of scope (deferred for separate work)

- Moving label *policy* fetching (`fetchPoliciesWithFallback()` / `CompliancePowerShellService::getPolicies()`) to the bridge. When that lands, the rest of `CompliancePowerShellService` and the `COMPLIANCE_CERTIFICATE_*` env vars become deletable.
- Migration to MIP SDK (proven non-viable in GCC High during the spike).
- Bridge endpoint for label search/filter (today's implementation returns the full list; consumers filter Laravel-side).

## Notes for the implementer

- **Cloud-environment string normalization.** The bridge already accepts both `gcc-high` and `gcc_high` via `CloudEnvironmentConfig`. The new `PowerShellSdkRunner` follows the same pattern (`Replace('_','-')`).
- **Bridge cert thumbprint requirement.** `Connect-IPPSSession` cert auth requires a thumbprint, not a PFX path. If the bridge's deployment uses `BRIDGE_CERT_HOST_PATH` (PFX) rather than `BRIDGE_CERT_THUMBPRINT`, the runner will throw at first call. The README in Task 7 makes this explicit; if a user reports the failure mode, point them at the README.
- **Why the cache TTL is 5 minutes.** The Partner365 sync runs every 15 minutes. A 5-minute TTL means the second-and-third sync inside a 15-minute window can hit the cache, but the next-cycle sync gets fresh data. Labels rarely change; aggressive caching is safe.
- **Why PSObject mapping uses string-only access.** PSObject properties are dynamic. Reading `pso.Properties[name]?.Value?.ToString()` is the only reliable way to extract values across PowerShell versions. Don't be tempted to add typed casts — the runtime types vary and cause `InvalidCastException` in production but not in unit tests with synthetic `PSNoteProperty` values.
- **Expected number of tests added.** Bridge: 11 (8 service + 3 endpoint). Laravel: 4 + 3 = 7 (BridgeClientTest cases + new chain cases in SensitivityLabelServiceTest). Roughly 4–6 deletions in SensitivityLabelServiceTest and CompliancePowerShellServiceTest. Net: bridge tests up by 11, Laravel test count roughly flat.
- **If `composer run test` is run in full**, the same 66 unrelated failures from before persist. They are pre-existing (verified during the prior sweep-page work) and not caused by anything in this plan.
