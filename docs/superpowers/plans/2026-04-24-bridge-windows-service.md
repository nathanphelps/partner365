# Bridge Windows Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a first-class Windows Service deployment path for `partner365-bridge` alongside the existing Docker path — one binary, two hosting models — and ship PowerShell install/uninstall scripts that publish, configure, register, and start the service idempotently.

**Architecture:** Shift the bridge from raw `Environment.GetEnvironmentVariable` to a typed `BridgeOptions` bound via `.NET Configuration`, which natively merges `appsettings.json` (Windows Service) and env vars (Docker). Add cert-store thumbprint loading alongside the existing PFX-file path. Register `Host.UseWindowsService()` — a no-op on Linux. Ship `bridge/windows/Install-PartnerBridge.ps1` and `Uninstall-PartnerBridge.ps1`. The Docker path, HTTP API, auth mechanism, and Partner365-side code are not touched.

**Tech Stack:** .NET 9 (ASP.NET Core minimal API, `Microsoft.Extensions.Hosting.WindowsServices`, `Microsoft.Extensions.Options.DataAnnotations`); PowerShell 7+ for install scripts; xUnit + Moq for tests. No new runtime dependencies beyond `Microsoft.Extensions.Hosting.WindowsServices`.

**Spec:** `docs/superpowers/specs/2026-04-24-bridge-windows-service-design.md`

---

## File structure

**Bridge — new files:**
```
bridge/Partner365.Bridge/
  BridgeOptions.cs                           # typed config + validation
bridge/Partner365.Bridge/Services/
  LegacyEnvVarMapper.cs                      # BRIDGE_* → Bridge__* aliasing
bridge/Partner365.Bridge.Tests/
  BridgeOptionsValidationTests.cs
  CertificateLoaderThumbprintTests.cs
  LegacyEnvVarMapperTests.cs
bridge/windows/
  Install-PartnerBridge.ps1
  Uninstall-PartnerBridge.ps1
  README.md
  validate.md
```

**Bridge — modified files:**
```
bridge/Partner365.Bridge/Partner365.Bridge.csproj   # + Microsoft.Extensions.Hosting.WindowsServices
bridge/Partner365.Bridge/Program.cs                  # IOptions binding, UseWindowsService, ListenUrl
bridge/Partner365.Bridge/Services/CertificateLoader.cs   # Load(opts) dispatch; add LoadFromStore
bridge/Partner365.Bridge/appsettings.json            # Bridge:ListenUrl default
bridge/Partner365.Bridge.Tests/BridgeFactory.cs      # (no functional change, but re-verify after refactor)
```

**Docs — modified files:**
```
docs/admin/sensitivity-labels-sidecar-setup.md      # Windows Service section
CLAUDE.md                                            # one-line cross-reference
bridge/dev/validate.md                               # cross-reference to windows/validate.md
```

**Not touched** (deliberately): `docker-compose.yml`, `bridge/Dockerfile`, `.env.example`, any file under `app/`, `resources/`, `tests/Feature/`, or `database/`.

---

## Phase 1 — Bridge foundation

### Task 1: Add `Microsoft.Extensions.Hosting.WindowsServices` package

**Files:**
- Modify: `bridge/Partner365.Bridge/Partner365.Bridge.csproj`

- [ ] **Step 1: Add the package reference**

Open `bridge/Partner365.Bridge/Partner365.Bridge.csproj`. In the `<ItemGroup>` that contains existing `<PackageReference>` entries, add:

```xml
<PackageReference Include="Microsoft.Extensions.Hosting.WindowsServices" Version="9.0.0" />
```

The final `<ItemGroup>` should look like:

```xml
<ItemGroup>
  <PackageReference Include="Azure.Identity" Version="1.13.1" />
  <PackageReference Include="Microsoft.Extensions.Hosting.WindowsServices" Version="9.0.0" />
  <PackageReference Include="PnP.Framework" Version="1.17.0" />
  <PackageReference Include="System.Security.Cryptography.Pkcs" Version="9.0.0" />
</ItemGroup>
```

- [ ] **Step 2: Restore + build to confirm it resolves**

```bash
cd bridge && dotnet restore && dotnet build
```

Expected: `Build succeeded. 0 Error(s)`. Minor warnings from PnP.Framework are normal.

- [ ] **Step 3: Commit**

```bash
git add bridge/Partner365.Bridge/Partner365.Bridge.csproj
git commit -m "feat(bridge): add Microsoft.Extensions.Hosting.WindowsServices package"
```

---

### Task 2: `BridgeOptions` class with validation

**Files:**
- Create: `bridge/Partner365.Bridge/BridgeOptions.cs`
- Create: `bridge/Partner365.Bridge.Tests/BridgeOptionsValidationTests.cs`

- [ ] **Step 1: Write the failing tests**

`bridge/Partner365.Bridge.Tests/BridgeOptionsValidationTests.cs`:

```csharp
using System.ComponentModel.DataAnnotations;
using Partner365.Bridge;
using Xunit;

namespace Partner365.Bridge.Tests;

public class BridgeOptionsValidationTests
{
    private static BridgeOptions Valid() => new()
    {
        CloudEnvironment = "gcc_high",
        TenantId = "00000000-0000-0000-0000-000000000001",
        ClientId = "00000000-0000-0000-0000-000000000002",
        AdminSiteUrl = "https://contoso-admin.sharepoint.com",
        SharedSecret = "x",
        ListenUrl = "http://127.0.0.1:5300",
        CertPath = "C:/certs/bridge.pfx",
        CertPassword = "",
    };

    private static List<ValidationResult> ValidateAll(BridgeOptions opts)
    {
        var ctx = new ValidationContext(opts);
        var errors = new List<ValidationResult>();
        Validator.TryValidateObject(opts, ctx, errors, validateAllProperties: true);
        errors.AddRange(opts.ValidateCertSource());
        return errors;
    }

    [Fact]
    public void Valid_options_pass_validation()
    {
        Assert.Empty(ValidateAll(Valid()));
    }

    [Fact]
    public void Missing_TenantId_fails()
    {
        var opts = Valid() with { TenantId = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.TenantId)));
    }

    [Fact]
    public void Missing_ClientId_fails()
    {
        var opts = Valid() with { ClientId = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.ClientId)));
    }

    [Fact]
    public void Missing_SharedSecret_fails()
    {
        var opts = Valid() with { SharedSecret = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.SharedSecret)));
    }

    [Fact]
    public void Missing_both_cert_sources_fails()
    {
        var opts = Valid() with { CertPath = null, CertPassword = null, CertThumbprint = null };
        var errors = ValidateAll(opts);
        Assert.Contains(errors, e => e.ErrorMessage != null && e.ErrorMessage.Contains("CertThumbprint"));
    }

    [Fact]
    public void Thumbprint_only_passes()
    {
        var opts = Valid() with { CertPath = null, CertPassword = null, CertThumbprint = "ABCDEF1234" };
        Assert.Empty(ValidateAll(opts));
    }

    [Fact]
    public void Path_only_passes()
    {
        var opts = Valid() with { CertPath = "C:/certs/bridge.pfx", CertPassword = "pw", CertThumbprint = null };
        Assert.Empty(ValidateAll(opts));
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~BridgeOptionsValidationTests"
```

Expected: compile error — `BridgeOptions` not found.

- [ ] **Step 3: Write `BridgeOptions.cs`**

`bridge/Partner365.Bridge/BridgeOptions.cs`:

```csharp
using System.ComponentModel.DataAnnotations;

namespace Partner365.Bridge;

public sealed record BridgeOptions
{
    [Required(AllowEmptyStrings = false)]
    public string CloudEnvironment { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string TenantId { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string ClientId { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string AdminSiteUrl { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string SharedSecret { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string ListenUrl { get; init; } = "";

    public string? CertPath { get; init; }
    public string? CertPassword { get; init; }
    public string? CertThumbprint { get; init; }

    /// <summary>
    /// At least one of CertPath or CertThumbprint must be populated.
    /// Separate from DataAnnotations because it's a cross-field rule.
    /// </summary>
    public IEnumerable<ValidationResult> ValidateCertSource()
    {
        if (string.IsNullOrWhiteSpace(CertThumbprint) && string.IsNullOrWhiteSpace(CertPath))
        {
            yield return new ValidationResult(
                "Bridge:CertThumbprint (Windows cert store) or Bridge:CertPath + Bridge:CertPassword (PFX file) must be set.",
                new[] { nameof(CertThumbprint), nameof(CertPath) });
        }
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~BridgeOptionsValidationTests"
```

Expected: `Passed: 7, Failed: 0`.

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/BridgeOptions.cs bridge/Partner365.Bridge.Tests/BridgeOptionsValidationTests.cs
git commit -m "feat(bridge): add BridgeOptions with cross-field cert validation"
```

---

### Task 3: `LegacyEnvVarMapper`

**Files:**
- Create: `bridge/Partner365.Bridge/Services/LegacyEnvVarMapper.cs`
- Create: `bridge/Partner365.Bridge.Tests/LegacyEnvVarMapperTests.cs`

- [ ] **Step 1: Write the failing tests**

`bridge/Partner365.Bridge.Tests/LegacyEnvVarMapperTests.cs`:

```csharp
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LegacyEnvVarMapperTests : IDisposable
{
    // Keep track of env vars we set so we can clean up.
    private readonly List<string> _touchedKeys = new();

    private void Set(string key, string? value)
    {
        _touchedKeys.Add(key);
        Environment.SetEnvironmentVariable(key, value);
    }

    public void Dispose()
    {
        foreach (var k in _touchedKeys) Environment.SetEnvironmentVariable(k, null);
    }

    [Theory]
    [InlineData("BRIDGE_CLOUD_ENVIRONMENT", "Bridge__CloudEnvironment", "gcc_high")]
    [InlineData("BRIDGE_TENANT_ID",         "Bridge__TenantId",         "t")]
    [InlineData("BRIDGE_CLIENT_ID",         "Bridge__ClientId",         "c")]
    [InlineData("BRIDGE_ADMIN_SITE_URL",    "Bridge__AdminSiteUrl",     "u")]
    [InlineData("BRIDGE_CERT_PATH",         "Bridge__CertPath",         "p")]
    [InlineData("BRIDGE_CERT_PASSWORD",     "Bridge__CertPassword",     "pw")]
    [InlineData("BRIDGE_CERT_THUMBPRINT",   "Bridge__CertThumbprint",   "abcd")]
    [InlineData("BRIDGE_SHARED_SECRET",     "Bridge__SharedSecret",     "s")]
    public void Maps_flat_env_var_to_double_underscore_form(string flat, string mapped, string value)
    {
        Set(flat, value);
        // Also clear any pre-existing mapped value to avoid leaking between theory rows.
        Set(mapped, null);

        LegacyEnvVarMapper.Apply();

        Assert.Equal(value, Environment.GetEnvironmentVariable(mapped));
    }

    [Fact]
    public void Does_not_overwrite_existing_double_underscore_form()
    {
        Set("BRIDGE_TENANT_ID", "flat-value");
        Set("Bridge__TenantId", "explicit-value");

        LegacyEnvVarMapper.Apply();

        // If both are set, the explicit double-underscore one wins — matches .NET Config precedence.
        Assert.Equal("explicit-value", Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }

    [Fact]
    public void Ignores_unset_flat_vars()
    {
        // No flat BRIDGE_TENANT_ID set.
        Set("BRIDGE_TENANT_ID", null);
        Set("Bridge__TenantId", null);

        LegacyEnvVarMapper.Apply();

        Assert.Null(Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }

    [Fact]
    public void Ignores_empty_flat_vars()
    {
        Set("BRIDGE_TENANT_ID", "");
        Set("Bridge__TenantId", null);

        LegacyEnvVarMapper.Apply();

        Assert.Null(Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~LegacyEnvVarMapperTests"
```

Expected: compile error — `LegacyEnvVarMapper` not found.

- [ ] **Step 3: Write `LegacyEnvVarMapper.cs`**

`bridge/Partner365.Bridge/Services/LegacyEnvVarMapper.cs`:

```csharp
namespace Partner365.Bridge.Services;

/// <summary>
/// Copies legacy flat `BRIDGE_*` environment variables into the double-underscore
/// form (<c>Bridge__TenantId</c>) that .NET Configuration reads as nested keys
/// (<c>Bridge:TenantId</c>).
///
/// Purpose: docker-compose.yml already ships with flat names. Rather than force
/// admins to edit their compose file, we alias the flat form at startup.
/// </summary>
public static class LegacyEnvVarMapper
{
    private static readonly (string Flat, string Mapped)[] Aliases =
    {
        ("BRIDGE_CLOUD_ENVIRONMENT", "Bridge__CloudEnvironment"),
        ("BRIDGE_TENANT_ID",         "Bridge__TenantId"),
        ("BRIDGE_CLIENT_ID",         "Bridge__ClientId"),
        ("BRIDGE_ADMIN_SITE_URL",    "Bridge__AdminSiteUrl"),
        ("BRIDGE_CERT_PATH",         "Bridge__CertPath"),
        ("BRIDGE_CERT_PASSWORD",     "Bridge__CertPassword"),
        ("BRIDGE_CERT_THUMBPRINT",   "Bridge__CertThumbprint"),
        ("BRIDGE_SHARED_SECRET",     "Bridge__SharedSecret"),
    };

    public static void Apply()
    {
        foreach (var (flat, mapped) in Aliases)
        {
            var flatValue = Environment.GetEnvironmentVariable(flat);
            if (string.IsNullOrWhiteSpace(flatValue)) continue;

            // Don't clobber an explicit double-underscore override.
            var existing = Environment.GetEnvironmentVariable(mapped);
            if (!string.IsNullOrWhiteSpace(existing)) continue;

            Environment.SetEnvironmentVariable(mapped, flatValue);
        }
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~LegacyEnvVarMapperTests"
```

Expected: `Passed: 11, Failed: 0` (8 theory rows + 3 facts).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/LegacyEnvVarMapper.cs bridge/Partner365.Bridge.Tests/LegacyEnvVarMapperTests.cs
git commit -m "feat(bridge): add LegacyEnvVarMapper for flat BRIDGE_* env var aliasing"
```

---

## Phase 2 — CertificateLoader: store + dispatch

### Task 4: `CertificateLoader.Load(BridgeOptions)` dispatcher

**Files:**
- Modify: `bridge/Partner365.Bridge/Services/CertificateLoader.cs`
- Create: `bridge/Partner365.Bridge.Tests/CertificateLoaderThumbprintTests.cs` (will be filled in Task 5)

- [ ] **Step 1: Write a failing test for the dispatch logic**

Add this to `bridge/Partner365.Bridge.Tests/CertificateLoaderTests.cs` (append at end of class, before the closing brace):

```csharp
    [Fact]
    public void Load_throws_when_neither_path_nor_thumbprint_set()
    {
        var opts = new BridgeOptions
        {
            CloudEnvironment = "gcc_high",
            TenantId = "t", ClientId = "c",
            AdminSiteUrl = "https://x-admin.sharepoint.com",
            SharedSecret = "s",
            ListenUrl = "http://127.0.0.1:5300",
            // CertPath + CertThumbprint intentionally null
        };

        var ex = Assert.Throws<InvalidOperationException>(() => CertificateLoader.Load(opts));
        Assert.Contains("cert source not configured", ex.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Load_dispatches_to_pfx_when_CertPath_set()
    {
        var opts = new BridgeOptions
        {
            CloudEnvironment = "gcc_high",
            TenantId = "t", ClientId = "c",
            AdminSiteUrl = "https://x-admin.sharepoint.com",
            SharedSecret = "s",
            ListenUrl = "http://127.0.0.1:5300",
            CertPath = _tempPfxPath,
            CertPassword = _password,
        };

        var cert = CertificateLoader.Load(opts);
        Assert.NotNull(cert);
        Assert.True(cert.HasPrivateKey);
    }
```

Add this using at the top of the file if not already present:

```csharp
using Partner365.Bridge;
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderTests"
```

Expected: FAIL — `CertificateLoader.Load` method does not exist.

- [ ] **Step 3: Add `Load` dispatcher to `CertificateLoader`**

Edit `bridge/Partner365.Bridge/Services/CertificateLoader.cs`. Replace the file contents:

```csharp
using System.Security.Cryptography.X509Certificates;

namespace Partner365.Bridge.Services;

public static class CertificateLoader
{
    public static X509Certificate2 Load(BridgeOptions opts)
    {
        // Thumbprint wins if both set — admins consolidating on the Windows cert
        // store shouldn't have to clear CertPath for it to take effect.
        if (!string.IsNullOrWhiteSpace(opts.CertThumbprint))
        {
            return LoadFromStore(opts.CertThumbprint!);
        }

        if (!string.IsNullOrWhiteSpace(opts.CertPath))
        {
            return LoadFromPfx(opts.CertPath!, opts.CertPassword ?? "");
        }

        throw new InvalidOperationException(
            "Bridge cert source not configured. Set Bridge:CertThumbprint " +
            "(Windows cert store) or Bridge:CertPath + Bridge:CertPassword (PFX file).");
    }

    public static X509Certificate2 LoadFromPfx(string path, string password)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException($"Certificate PFX not found at '{path}'.", path);
        }

        return X509CertificateLoader.LoadPkcs12FromFile(
            path,
            password,
            X509KeyStorageFlags.MachineKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);
    }

    // LoadFromStore is implemented in Task 5.
    private static X509Certificate2 LoadFromStore(string thumbprint)
    {
        throw new NotImplementedException("LoadFromStore is implemented in Task 5.");
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderTests"
```

Expected: both new tests plus the original 4 PFX tests pass. Total 6 passing.

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Services/CertificateLoader.cs bridge/Partner365.Bridge.Tests/CertificateLoaderTests.cs
git commit -m "feat(bridge): add CertificateLoader.Load dispatcher over BridgeOptions"
```

---

### Task 5: `LoadFromStore` (Windows cert store by thumbprint)

**Files:**
- Modify: `bridge/Partner365.Bridge/Services/CertificateLoader.cs`
- Create: `bridge/Partner365.Bridge.Tests/CertificateLoaderThumbprintTests.cs`

Note: `LocalMachine\My` is not writable from a test process without elevation, and we don't want tests requiring admin. We test `LoadFromStore` against `CurrentUser\My` using a test override. To enable that, introduce an internal overload that takes a `StoreLocation`.

- [ ] **Step 1: Write the failing tests**

`bridge/Partner365.Bridge.Tests/CertificateLoaderThumbprintTests.cs`:

```csharp
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CertificateLoaderThumbprintTests : IDisposable
{
    private readonly X509Certificate2 _cert;
    private readonly string _thumbprint;
    private readonly X509Store _store;

    public CertificateLoaderThumbprintTests()
    {
        using var rsa = RSA.Create(2048);
        var req = new CertificateRequest("CN=ThumbprintTest", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        // Persist the key so we get HasPrivateKey=true after re-reading from the store.
        _cert = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
        var pfxBytes = _cert.Export(X509ContentType.Pfx, "");
        _cert = X509CertificateLoader.LoadPkcs12(
            pfxBytes, "",
            X509KeyStorageFlags.UserKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);

        _thumbprint = _cert.Thumbprint;

        _store = new X509Store(StoreName.My, StoreLocation.CurrentUser);
        _store.Open(OpenFlags.ReadWrite);
        _store.Add(_cert);
    }

    public void Dispose()
    {
        try { _store.Remove(_cert); } catch { /* best effort */ }
        _store.Close();
        _cert.Dispose();
    }

    [Fact]
    public void Finds_cert_by_thumbprint()
    {
        var loaded = CertificateLoader.LoadFromStoreForTests(_thumbprint, StoreLocation.CurrentUser);
        Assert.NotNull(loaded);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Normalizes_thumbprint_with_spaces()
    {
        // MMC's "Copy Thumbprint" inserts spaces every 2 chars.
        var spaced = string.Join(" ", Enumerable.Range(0, _thumbprint.Length / 2)
            .Select(i => _thumbprint.Substring(i * 2, 2)));
        var loaded = CertificateLoader.LoadFromStoreForTests(spaced, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Normalizes_thumbprint_with_U200E_marker()
    {
        // MMC often prepends U+200E (Left-to-Right Mark) when copying.
        var prefixed = "‎" + _thumbprint;
        var loaded = CertificateLoader.LoadFromStoreForTests(prefixed, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Is_case_insensitive_on_thumbprint()
    {
        var lower = _thumbprint.ToLowerInvariant();
        var loaded = CertificateLoader.LoadFromStoreForTests(lower, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Throws_when_thumbprint_not_found()
    {
        var ex = Assert.Throws<InvalidOperationException>(() =>
            CertificateLoader.LoadFromStoreForTests(
                "0000000000000000000000000000000000000000",
                StoreLocation.CurrentUser));
        Assert.Contains("not found", ex.Message, StringComparison.OrdinalIgnoreCase);
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderThumbprintTests"
```

Expected: FAIL — `LoadFromStoreForTests` does not exist, and the current `LoadFromStore` stub throws `NotImplementedException`.

- [ ] **Step 3: Implement `LoadFromStore` + test-only overload**

Replace `bridge/Partner365.Bridge/Services/CertificateLoader.cs` entirely with:

```csharp
using System.Security.Cryptography.X509Certificates;

namespace Partner365.Bridge.Services;

public static class CertificateLoader
{
    public static X509Certificate2 Load(BridgeOptions opts)
    {
        // Thumbprint wins if both set — admins consolidating on the Windows cert
        // store shouldn't have to clear CertPath for it to take effect.
        if (!string.IsNullOrWhiteSpace(opts.CertThumbprint))
        {
            return LoadFromStore(opts.CertThumbprint!, StoreLocation.LocalMachine);
        }

        if (!string.IsNullOrWhiteSpace(opts.CertPath))
        {
            return LoadFromPfx(opts.CertPath!, opts.CertPassword ?? "");
        }

        throw new InvalidOperationException(
            "Bridge cert source not configured. Set Bridge:CertThumbprint " +
            "(Windows cert store) or Bridge:CertPath + Bridge:CertPassword (PFX file).");
    }

    public static X509Certificate2 LoadFromPfx(string path, string password)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException($"Certificate PFX not found at '{path}'.", path);
        }

        return X509CertificateLoader.LoadPkcs12FromFile(
            path,
            password,
            X509KeyStorageFlags.MachineKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);
    }

    /// <summary>Test-only overload. Production code always uses LocalMachine.</summary>
    internal static X509Certificate2 LoadFromStoreForTests(string thumbprint, StoreLocation location)
        => LoadFromStore(thumbprint, location);

    private static X509Certificate2 LoadFromStore(string thumbprint, StoreLocation location)
    {
        // MMC's "Copy Thumbprint" paste often includes U+200E (LTR mark) and spaces.
        // Strip non-alphanumerics and upper-case before comparing.
        var normalized = new string(thumbprint.Where(char.IsLetterOrDigit).ToArray())
            .ToUpperInvariant();

        using var store = new X509Store(StoreName.My, location);
        store.Open(OpenFlags.ReadOnly);
        var matches = store.Certificates.Find(
            X509FindType.FindByThumbprint, normalized, validOnly: false);

        if (matches.Count == 0)
        {
            var storeName = location == StoreLocation.LocalMachine ? "LocalMachine\\My" : "CurrentUser\\My";
            throw new InvalidOperationException(
                $"Certificate with thumbprint '{normalized}' not found in {storeName}.");
        }

        var cert = matches[0];
        if (!cert.HasPrivateKey)
        {
            throw new InvalidOperationException(
                $"Certificate '{normalized}' has no private key. Re-import the PFX " +
                "(not just the .cer) and ensure the service account can read the key.");
        }

        return cert;
    }
}
```

Also add `InternalsVisibleTo` so the test project can call `LoadFromStoreForTests`. In `bridge/Partner365.Bridge/Partner365.Bridge.csproj`, inside the existing `<ItemGroup>` (create one if needed), add:

```xml
<ItemGroup>
  <InternalsVisibleTo Include="Partner365.Bridge.Tests" />
</ItemGroup>
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~CertificateLoaderThumbprintTests"
```

Expected: `Passed: 5, Failed: 0`. Tests run on Windows and Linux — `CurrentUser\My` works on both in .NET 9 (on Linux it uses an in-process OpenSSL-backed store).

- [ ] **Step 5: Run ALL bridge tests to confirm no regressions**

```bash
cd bridge && dotnet test
```

Expected: All previous tests still pass, plus the 5 new thumbprint tests.

- [ ] **Step 6: Commit**

```bash
git add bridge/Partner365.Bridge/Services/CertificateLoader.cs bridge/Partner365.Bridge/Partner365.Bridge.csproj bridge/Partner365.Bridge.Tests/CertificateLoaderThumbprintTests.cs
git commit -m "feat(bridge): add cert-store thumbprint loader with normalization"
```

---

## Phase 3 — Program.cs refactor

### Task 6: Wire `Program.cs` to `BridgeOptions`, `UseWindowsService`, `ListenUrl`

**Files:**
- Modify: `bridge/Partner365.Bridge/Program.cs`
- Modify: `bridge/Partner365.Bridge/appsettings.json`

This is the biggest mechanical change. The existing `Program.cs` reads flat env vars and constructs services imperatively. The new version binds `BridgeOptions`, applies `LegacyEnvVarMapper` first so Docker env vars still work, uses `Host.UseWindowsService()`, and reads `ListenUrl` from options.

- [ ] **Step 1: Update `appsettings.json` with default `ListenUrl`**

Replace `bridge/Partner365.Bridge/appsettings.json` contents:

```json
{
  "Logging": {
    "LogLevel": {
      "Default": "Information",
      "Microsoft.AspNetCore": "Warning"
    }
  },
  "AllowedHosts": "*",
  "Bridge": {
    "ListenUrl": "http://127.0.0.1:5300"
  }
}
```

- [ ] **Step 2: Rewrite `Program.cs`**

Replace `bridge/Partner365.Bridge/Program.cs` entirely:

```csharp
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Options;
using Partner365.Bridge;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

// 1) Bridge flat BRIDGE_* env vars into the Bridge__* form .NET Config expects.
//    Docker admins can keep their compose file as-is; no breaking change.
LegacyEnvVarMapper.Apply();

var builder = WebApplication.CreateBuilder(args);

// 2) Windows Service support. No-op when process isn't launched by the SCM —
//    same binary runs as a console on dev boxes and as a container on Linux.
builder.Host.UseWindowsService(o => o.ServiceName = "PartnerBridge");

// 3) Bind + validate BridgeOptions at startup. Missing fields fail `Start-Service`
//    and `docker compose up` identically.
builder.Services
    .AddOptions<BridgeOptions>()
    .Bind(builder.Configuration.GetSection("Bridge"))
    .ValidateDataAnnotations()
    .Validate(
        o => !o.ValidateCertSource().Any(),
        "Bridge:CertPath or Bridge:CertThumbprint must be set (see BridgeOptions.ValidateCertSource).")
    .ValidateOnStart();

// Resolve options for startup-only wiring (cert load, cloud env, listen URL).
// The bound options are also available via IOptions<BridgeOptions> at runtime.
var opts = builder.Configuration.GetSection("Bridge").Get<BridgeOptions>()
    ?? throw new InvalidOperationException("Bridge configuration section missing.");

// 4) Listen URL: explicit ASPNETCORE_URLS (from .NET Host) wins if set;
//    otherwise we use Bridge:ListenUrl from configuration.
if (string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ASPNETCORE_URLS")))
{
    builder.WebHost.UseUrls(opts.ListenUrl);
}

// 5) Cloud environment + cert.
var cloudCfg = CloudEnvironmentConfig.For(opts.CloudEnvironment, opts.AdminSiteUrl);

// The test harness (BridgeFactory) uses CertPath="__TEST__" as a sentinel so
// the host boots without a real cert, then injects a mock ICsomOperations.
// /health reports thumbprint as null rather than leaking the sentinel.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (opts.CertPath != "__TEST__")
{
    cert = CertificateLoader.Load(opts);
}

builder.Services.AddSingleton(cloudCfg);
builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: cloudCfg.CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint));

if (cert is not null)
{
    builder.Services.AddSingleton<ICsomOperations>(
        _ => new PnPCsomOperations(cloudCfg, opts.TenantId, opts.ClientId, opts.AdminSiteUrl, cert));
}
// else: the test harness injects a mock ICsomOperations.

builder.Services.AddSingleton<SharePointCsomService>();

builder.Services.ConfigureHttpJsonOptions(jsonOpts =>
{
    jsonOpts.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    jsonOpts.SerializerOptions.DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull;
});

var app = builder.Build();

// 6) Shared-secret middleware. Resolve the final SharedSecret through IOptions so
//    the tests (which rebind via the test host) see the same value.
var secret = app.Services.GetRequiredService<IOptions<BridgeOptions>>().Value.SharedSecret;
var middlewareLogger = app.Services.GetRequiredService<ILoggerFactory>().CreateLogger<SharedSecretMiddleware>();
app.Use(async (ctx, next) =>
{
    var mw = new SharedSecretMiddleware(_ => next(), secret, middlewareLogger);
    await mw.Invoke(ctx);
});

// 7) Endpoints — unchanged from before.
app.MapGet("/health", (BridgeStartupInfo info) =>
    Results.Ok(new HealthResponse("ok", info.CloudEnvironmentName, info.CertThumbprint)));

app.MapPost("/v1/sites/label", async (
    [FromQuery] bool? overwrite,
    [FromBody] SetLabelRequest? req,
    SharePointCsomService svc,
    IOptions<BridgeOptions> bridgeOpts,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");

    if (req is null)
    {
        return BadRequest(requestId, "Request body is required.");
    }

    try
    {
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, bridgeOpts.Value.AdminSiteUrl);
    }
    catch (ArgumentException ex)
    {
        log.LogInformation("{RequestId} set-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }

    try
    {
        var result = await svc.SetLabelAsync(req.SiteUrl, req.LabelId, overwrite ?? false, ct);
        log.LogInformation("{RequestId} set-label site={Site} fastPath={FastPath}", requestId, req.SiteUrl, result.FastPath);
        return Results.Ok(new SetLabelResponse(req.SiteUrl, req.LabelId, result.FastPath));
    }
    catch (OperationCanceledException)
    {
        throw;
    }
    catch (LabelConflictException ex)
    {
        log.LogInformation("{RequestId} set-label conflict site={Site}", requestId, req.SiteUrl);
        return Results.Json(new ErrorResponse(new ErrorBody("already_labeled", ex.Message, requestId)), statusCode: 409);
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogError(ex, "{RequestId} set-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
    }
});

app.MapPost("/v1/sites/label:read", async (
    [FromBody] ReadLabelRequest? req,
    SharePointCsomService svc,
    IOptions<BridgeOptions> bridgeOpts,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");

    if (req is null)
    {
        return BadRequest(requestId, "Request body is required.");
    }

    try
    {
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, bridgeOpts.Value.AdminSiteUrl);
    }
    catch (ArgumentException ex)
    {
        log.LogInformation("{RequestId} read-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }

    try
    {
        var labelId = await svc.ReadLabelAsync(req.SiteUrl, ct);
        log.LogInformation("{RequestId} read-label site={Site} labelId={LabelId}", requestId, req.SiteUrl, labelId ?? "(none)");
        return Results.Ok(new ReadLabelResponse(req.SiteUrl, labelId));
    }
    catch (OperationCanceledException)
    {
        throw;
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogError(ex, "{RequestId} read-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
    }
});

app.Run();

static IResult BadRequest(string requestId, string message) =>
    Results.Json(new ErrorResponse(new ErrorBody("bad_request", message, requestId)), statusCode: 400);

static string SanitizeMessage(Exception ex) =>
    $"Bridge operation failed ({ex.GetType().Name}). See bridge logs.";

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string? CertThumbprint);

public partial class Program { }
```

Key differences from the existing `Program.cs`:
- No more `RequireEnv(...)` — replaced by `BridgeOptions` binding with `ValidateOnStart`.
- Calls `LegacyEnvVarMapper.Apply()` first so flat `BRIDGE_*` env vars (docker-compose) still work.
- `UseWindowsService(o => o.ServiceName = "PartnerBridge")` added; no-op on Linux.
- `UseUrls(opts.ListenUrl)` explicitly; `ASPNETCORE_URLS` still wins (ASP.NET Core's default).
- `CertificateLoader.Load(opts)` dispatches between thumbprint and PFX.
- Endpoints read `AdminSiteUrl` from `IOptions<BridgeOptions>` instead of a captured local.

- [ ] **Step 3: Run the whole bridge test suite**

```bash
cd bridge && dotnet test
```

Expected: ALL tests pass (54 from Phase-0 + ~13 new from Phases 1–2 = ~67 total). If `BridgeStartupTests` fails with "Bridge:TenantId required" or similar, your `BridgeFactory` isn't setting the legacy vars early enough — see Task 7.

- [ ] **Step 4: Smoke-test the binary locally**

This is a sanity check that the process actually starts. Set env vars and run:

```powershell
$env:BRIDGE_CLOUD_ENVIRONMENT = "gcc_high"
$env:BRIDGE_TENANT_ID         = "00000000-0000-0000-0000-000000000001"
$env:BRIDGE_CLIENT_ID         = "00000000-0000-0000-0000-000000000002"
$env:BRIDGE_ADMIN_SITE_URL    = "https://contoso-admin.sharepoint.com"
$env:BRIDGE_CERT_PATH         = "__TEST__"   # sentinel skips cert load
$env:BRIDGE_SHARED_SECRET     = "smoke-test"
cd bridge\Partner365.Bridge
dotnet run -- --urls "http://127.0.0.1:5300"
```

In another terminal:
```bash
curl http://127.0.0.1:5300/health
```
Expected: `{"status":"ok","cloudEnvironment":"gcc-high","certThumbprint":null}`

Stop with Ctrl+C. Clear the env vars:
```powershell
Remove-Item Env:\BRIDGE_*
```

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge/Program.cs bridge/Partner365.Bridge/appsettings.json
git commit -m "feat(bridge): bind BridgeOptions, add UseWindowsService, read ListenUrl from config"
```

---

### Task 7: Update `BridgeFactory` to preserve integration test behavior

**Files:**
- Modify: `bridge/Partner365.Bridge.Tests/BridgeFactory.cs`

Today `BridgeFactory` sets legacy `BRIDGE_*` env vars in `ConfigureWebHost`. After Task 3/6, those get translated to `Bridge__*` by `LegacyEnvVarMapper.Apply()`, which runs at the very top of `Program.cs` — BEFORE the test factory's `ConfigureWebHost` runs. So the test vars must be set earlier, in the factory's static initializer or constructor.

- [ ] **Step 1: Run integration tests — see the failure shape**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~BridgeStartup"
```

If the previous `BridgeFactory` pattern still works (env vars set in `ConfigureWebHost` get picked up before `Program.cs` binding), expected: all pass. If instead you see "Bridge:TenantId is required" or similar from `ValidateOnStart`, continue to Step 2.

- [ ] **Step 2: Move env var setup to the factory's constructor**

Replace `bridge/Partner365.Bridge.Tests/BridgeFactory.cs`:

```csharp
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Moq;
using Partner365.Bridge.Services;

namespace Partner365.Bridge.Tests;

/// <summary>
/// WebApplicationFactory that sets required env vars before the host boots
/// and replaces <see cref="ICsomOperations"/> with a Moq double.
/// </summary>
public sealed class BridgeFactory : WebApplicationFactory<Program>
{
    public Mock<ICsomOperations> Ops { get; } = new();

    // Constructor runs before CreateDefaultBuilder → LegacyEnvVarMapper.Apply() →
    // ConfigurationBuilder. Setting env vars here ensures they are visible both to
    // LegacyEnvVarMapper and to .NET Configuration.
    public BridgeFactory()
    {
        Environment.SetEnvironmentVariable("BRIDGE_CLOUD_ENVIRONMENT", "commercial");
        Environment.SetEnvironmentVariable("BRIDGE_TENANT_ID", "11111111-1111-1111-1111-111111111111");
        Environment.SetEnvironmentVariable("BRIDGE_CLIENT_ID", "22222222-2222-2222-2222-222222222222");
        Environment.SetEnvironmentVariable("BRIDGE_ADMIN_SITE_URL", "https://test-admin.sharepoint.com");
        Environment.SetEnvironmentVariable("BRIDGE_CERT_PATH", "__TEST__");
        Environment.SetEnvironmentVariable("BRIDGE_CERT_PASSWORD", "");
        Environment.SetEnvironmentVariable("BRIDGE_SHARED_SECRET", "unit-test-secret");
    }

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        builder.ConfigureServices(services =>
        {
            var descriptor = services.FirstOrDefault(d => d.ServiceType == typeof(ICsomOperations));
            if (descriptor is not null) services.Remove(descriptor);
            services.AddSingleton(Ops.Object);
        });
    }
}
```

- [ ] **Step 3: Run integration tests — expect PASS**

```bash
cd bridge && dotnet test --filter "FullyQualifiedName~BridgeStartup"
```

Expected: all `BridgeStartupTests` pass.

- [ ] **Step 4: Run ALL bridge tests**

```bash
cd bridge && dotnet test
```

Expected: all pass. Baseline before this branch was 54 passing; after Phases 1/2/3 should be around 67 (54 + 7 BridgeOptions + 4 LegacyEnvVarMapper for theory + 3 facts + 2 dispatcher + 5 thumbprint = depends on exact numbers).

- [ ] **Step 5: Commit**

```bash
git add bridge/Partner365.Bridge.Tests/BridgeFactory.cs
git commit -m "test(bridge): set BridgeFactory env vars in constructor for options binding"
```

---

## Phase 4 — Windows install scripts

### Task 8: `Install-PartnerBridge.ps1`

**Files:**
- Create: `bridge/windows/Install-PartnerBridge.ps1`

Infrastructure script — no TDD, but defensive: idempotent, parameter-checked, verifies end-state.

- [ ] **Step 1: Create the script**

`bridge/windows/Install-PartnerBridge.ps1`:

```powershell
<#
.SYNOPSIS
Installs or updates partner365-bridge as a Windows Service.

.DESCRIPTION
Publishes the bridge as a self-contained win-x64 app into InstallPath,
writes appsettings.Production.json (merging with existing values on update),
ACLs the config file to LocalSystem read-only, registers or reconfigures
the service via sc.exe, starts it with a 30-second readiness timeout, and
verifies the /health endpoint responds.

Idempotent: safe to re-run with new or identical parameters. Existing
secrets (CertPassword, SharedSecret) are preserved when the corresponding
parameters are not supplied on re-runs.

.PARAMETER TenantId
Entra tenant GUID. Required on first install.

.PARAMETER ClientId
App registration client GUID. Required on first install.

.PARAMETER AdminSiteUrl
SharePoint admin URL (e.g., https://contoso-admin.sharepoint.com or .us).
Required on first install.

.PARAMETER SharedSecret
Random string used to authenticate Partner365 -> bridge calls. Required on
first install. Recommend: -join ((1..64)|%{'{0:x}' -f (Get-Random -Max 16)}).

.PARAMETER CertPath
Path to PFX file with the auth cert. Mutually exclusive with -CertThumbprint;
exactly one must be supplied on first install.

.PARAMETER CertPassword
Password for the PFX specified by -CertPath. Empty string allowed.

.PARAMETER CertThumbprint
Thumbprint of a certificate in LocalMachine\My. Mutually exclusive with -CertPath.

.PARAMETER CloudEnvironment
"commercial" or "gcc_high". Default: "commercial".

.PARAMETER InstallPath
Install directory. Default: C:\Partner365Bridge.

.PARAMETER ServiceName
Windows service identifier. Default: PartnerBridge.

.PARAMETER DisplayName
Windows service display name. Default: "Partner365 Bridge".

.PARAMETER ListenUrl
HTTP URL the service binds to. Default: http://127.0.0.1:5300.

.PARAMETER RepoRoot
Bridge source root (folder containing Partner365.Bridge.csproj). Default:
two levels up from this script.

.EXAMPLE
.\Install-PartnerBridge.ps1 `
    -TenantId      'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' `
    -ClientId      'yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy' `
    -AdminSiteUrl  'https://contoso-admin.sharepoint.us' `
    -CloudEnvironment 'gcc_high' `
    -CertPath      'C:\certs\bridge.pfx' `
    -CertPassword  'pfx-password' `
    -SharedSecret  (-join ((1..64)|%{'{0:x}' -f (Get-Random -Max 16)}))
#>
[CmdletBinding()]
param(
    [string]$TenantId,
    [string]$ClientId,
    [string]$AdminSiteUrl,
    [string]$SharedSecret,
    [string]$CertPath,
    [string]$CertPassword = '',
    [string]$CertThumbprint,
    [ValidateSet('commercial','gcc_high')]
    [string]$CloudEnvironment = 'commercial',
    [string]$InstallPath = 'C:\Partner365Bridge',
    [string]$ServiceName = 'PartnerBridge',
    [string]$DisplayName = 'Partner365 Bridge',
    [string]$ListenUrl = 'http://127.0.0.1:5300',
    [string]$RepoRoot
)

$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# 1) Elevation + dotnet + repo-root checks
# ---------------------------------------------------------------------------
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal $identity
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Run this script in an elevated PowerShell session (Run as Administrator)."
}

$dotnetVersion = & dotnet --version 2>$null
if (-not $dotnetVersion -or [Version]::Parse(($dotnetVersion -split '-')[0]) -lt [Version]'9.0.0') {
    throw ".NET SDK 9.0+ is required. Install from https://dotnet.microsoft.com/download/dotnet/9.0"
}
Write-Host "dotnet version: $dotnetVersion"

if (-not $RepoRoot) {
    $RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
}
$csproj = Join-Path $RepoRoot 'bridge\Partner365.Bridge\Partner365.Bridge.csproj'
if (-not (Test-Path $csproj)) {
    throw "Bridge csproj not found at $csproj. Pass -RepoRoot to point at the repo root."
}

# ---------------------------------------------------------------------------
# 2) Determine what we already have installed (for merge + idempotency)
# ---------------------------------------------------------------------------
$configFile = Join-Path $InstallPath 'appsettings.Production.json'
$existing = $null
if (Test-Path $configFile) {
    Write-Host "Existing config found at $configFile — merging with supplied parameters."
    $existing = Get-Content $configFile -Raw | ConvertFrom-Json
}

# Build final config. Supplied parameters override existing, existing overrides defaults.
$final = [ordered]@{
    CloudEnvironment = $CloudEnvironment
    TenantId         = if ($TenantId)         { $TenantId }         elseif ($existing.Bridge.TenantId)         { $existing.Bridge.TenantId }         else { $null }
    ClientId         = if ($ClientId)         { $ClientId }         elseif ($existing.Bridge.ClientId)         { $existing.Bridge.ClientId }         else { $null }
    AdminSiteUrl     = if ($AdminSiteUrl)     { $AdminSiteUrl }     elseif ($existing.Bridge.AdminSiteUrl)     { $existing.Bridge.AdminSiteUrl }     else { $null }
    SharedSecret     = if ($SharedSecret)     { $SharedSecret }     elseif ($existing.Bridge.SharedSecret)     { $existing.Bridge.SharedSecret }     else { $null }
    ListenUrl        = $ListenUrl
    CertPath         = if ($CertPath)         { $CertPath }         elseif ($existing.Bridge.CertPath)         { $existing.Bridge.CertPath }         else { $null }
    CertPassword     = if ($PSBoundParameters.ContainsKey('CertPassword')) { $CertPassword } elseif ($existing.Bridge.CertPassword) { $existing.Bridge.CertPassword } else { '' }
    CertThumbprint   = if ($CertThumbprint)   { $CertThumbprint }   elseif ($existing.Bridge.CertThumbprint)   { $existing.Bridge.CertThumbprint }   else { $null }
}

# Prefer supplied override: if caller provided exactly one of CertPath/CertThumbprint, clear the other.
if ($CertThumbprint -and -not $CertPath) { $final.CertPath = $null; $final.CertPassword = '' }
if ($CertPath -and -not $CertThumbprint) { $final.CertThumbprint = $null }

# ---------------------------------------------------------------------------
# 3) Validate final config
# ---------------------------------------------------------------------------
foreach ($req in 'TenantId','ClientId','AdminSiteUrl','SharedSecret') {
    if (-not $final[$req]) {
        throw "Required parameter -$req missing. Supply it explicitly (and any other parameters) on first install."
    }
}
if (-not $final.CertPath -and -not $final.CertThumbprint) {
    throw "One of -CertPath or -CertThumbprint must be supplied."
}
if ($final.CertPath -and -not (Test-Path $final.CertPath)) {
    throw "CertPath '$($final.CertPath)' does not exist."
}
if ($final.CertThumbprint) {
    $normalized = ($final.CertThumbprint -replace '[^A-Za-z0-9]','').ToUpperInvariant()
    $found = Get-ChildItem Cert:\LocalMachine\My | Where-Object { $_.Thumbprint -eq $normalized }
    if (-not $found) {
        throw "Certificate with thumbprint '$normalized' not found in Cert:\LocalMachine\My. Import the PFX first."
    }
    if (-not $found.HasPrivateKey) {
        throw "Certificate '$normalized' has no private key. Re-import with the PFX (not just the .cer)."
    }
    $final.CertThumbprint = $normalized
}

# ---------------------------------------------------------------------------
# 4) Stop existing service (if any)
# ---------------------------------------------------------------------------
$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc -and $svc.Status -eq 'Running') {
    Write-Host "Stopping existing service $ServiceName..."
    Stop-Service -Name $ServiceName -Force
}

# ---------------------------------------------------------------------------
# 5) Publish
# ---------------------------------------------------------------------------
if (-not (Test-Path $InstallPath)) {
    New-Item -ItemType Directory -Path $InstallPath | Out-Null
}
Write-Host "Publishing bridge to $InstallPath..."
& dotnet publish $csproj -c Release -r win-x64 --self-contained true -o $InstallPath /p:PublishSingleFile=false | Out-Host
if ($LASTEXITCODE -ne 0) {
    throw "dotnet publish failed with exit code $LASTEXITCODE"
}

# ---------------------------------------------------------------------------
# 6) Write appsettings.Production.json
# ---------------------------------------------------------------------------
$json = [ordered]@{
    Bridge = $final
} | ConvertTo-Json -Depth 4
Set-Content -Path $configFile -Value $json -Encoding UTF8
Write-Host "Wrote $configFile"

# ---------------------------------------------------------------------------
# 7) ACL the config file
# ---------------------------------------------------------------------------
& icacls $configFile /inheritance:r | Out-Null
& icacls $configFile /grant:r "NT AUTHORITY\SYSTEM:(R)" "BUILTIN\Administrators:(F)" | Out-Null
Write-Host "ACL'd $configFile (SYSTEM read, Administrators full, no inheritance)."

# ---------------------------------------------------------------------------
# 8) Create or reconfigure the service
# ---------------------------------------------------------------------------
$exe = Join-Path $InstallPath 'Partner365.Bridge.exe'
if (-not (Test-Path $exe)) {
    throw "Published binary not found at $exe"
}

if ($svc) {
    Write-Host "Reconfiguring existing service $ServiceName..."
    & sc.exe config $ServiceName binPath= "`"$exe`"" start= delayed-auto obj= LocalSystem DisplayName= "`"$DisplayName`"" | Out-Host
} else {
    Write-Host "Creating service $ServiceName..."
    & sc.exe create $ServiceName binPath= "`"$exe`"" start= delayed-auto obj= LocalSystem DisplayName= "`"$DisplayName`"" | Out-Host
}
if ($LASTEXITCODE -ne 0) {
    throw "sc.exe failed with exit code $LASTEXITCODE"
}

& sc.exe description $ServiceName "Partner365 bridge for SharePoint CSOM sensitivity-label writes." | Out-Null
& sc.exe failure $ServiceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null

# ---------------------------------------------------------------------------
# 9) Start + wait for Running
# ---------------------------------------------------------------------------
Write-Host "Starting $ServiceName..."
Start-Service -Name $ServiceName

$deadline = (Get-Date).AddSeconds(30)
while ((Get-Service $ServiceName).Status -ne 'Running' -and (Get-Date) -lt $deadline) {
    Start-Sleep -Milliseconds 500
}
if ((Get-Service $ServiceName).Status -ne 'Running') {
    Write-Warning "Service did not reach Running within 30s. Recent Application-log events:"
    Get-WinEvent -LogName Application -MaxEvents 20 -ErrorAction SilentlyContinue |
        Where-Object { $_.ProviderName -like '*PartnerBridge*' -or $_.Message -like '*Partner365.Bridge*' } |
        Select-Object TimeCreated, LevelDisplayName, Message |
        Format-List | Out-Host
    throw "Service failed to start."
}

# ---------------------------------------------------------------------------
# 10) Health check
# ---------------------------------------------------------------------------
$healthUrl = "$($final.ListenUrl.TrimEnd('/'))/health"
for ($i = 1; $i -le 5; $i++) {
    try {
        $resp = Invoke-RestMethod -Uri $healthUrl -TimeoutSec 5
        Write-Host ""
        Write-Host "=== Install complete ===" -ForegroundColor Green
        Write-Host "Service:           $ServiceName ($DisplayName)"
        Write-Host "Install path:      $InstallPath"
        Write-Host "Listen URL:        $($final.ListenUrl)"
        Write-Host "Cloud env:         $($resp.cloudEnvironment)"
        if ($resp.certThumbprint) {
            Write-Host "Cert thumbprint:   $($resp.certThumbprint)"
        }
        Write-Host "Shared secret:     $($final.SharedSecret.Length) chars (paste into Partner365 Sweep Config)"
        Write-Host "Health response:   $($resp | ConvertTo-Json -Compress)"
        return
    } catch {
        Start-Sleep -Seconds 1
    }
}

throw "Service started but /health did not respond within 5 attempts. Check the Application event log."
```

- [ ] **Step 2: PowerShell lint check**

```powershell
cd bridge\windows
Get-Command Invoke-ScriptAnalyzer -ErrorAction SilentlyContinue
```

If PSScriptAnalyzer is installed, run:
```powershell
Invoke-ScriptAnalyzer -Path .\Install-PartnerBridge.ps1
```
Expected: no errors. Warnings about `$CertPassword` being in cleartext are expected and acceptable — secrets are ACL'd on disk, not hidden in the install script.

If PSScriptAnalyzer is not installed, skip this step.

- [ ] **Step 3: Syntax-only check**

```powershell
cd bridge\windows
$null = [System.Management.Automation.Language.Parser]::ParseFile(
    (Resolve-Path .\Install-PartnerBridge.ps1),
    [ref]$null, [ref]$null)
"OK"
```

Expected: `OK`. Anything else means a syntax error — fix before committing.

- [ ] **Step 4: Commit**

```bash
git add bridge/windows/Install-PartnerBridge.ps1
git commit -m "feat(bridge): add Install-PartnerBridge.ps1 Windows Service installer"
```

---

### Task 9: `Uninstall-PartnerBridge.ps1`

**Files:**
- Create: `bridge/windows/Uninstall-PartnerBridge.ps1`

- [ ] **Step 1: Create the script**

`bridge/windows/Uninstall-PartnerBridge.ps1`:

```powershell
<#
.SYNOPSIS
Removes the partner365-bridge Windows Service and (optionally) its install directory.

.PARAMETER ServiceName
Service identifier to remove. Default: PartnerBridge.

.PARAMETER InstallPath
Install directory to wipe. Default: C:\Partner365Bridge.

.PARAMETER KeepConfig
If set, preserves appsettings.Production.json and removes only runtime binaries.
Default: false (full wipe).

.EXAMPLE
.\Uninstall-PartnerBridge.ps1
.\Uninstall-PartnerBridge.ps1 -KeepConfig
#>
[CmdletBinding()]
param(
    [string]$ServiceName = 'PartnerBridge',
    [string]$InstallPath = 'C:\Partner365Bridge',
    [switch]$KeepConfig
)

$ErrorActionPreference = 'Stop'

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal $identity
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Run this script in an elevated PowerShell session."
}

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    if ($svc.Status -eq 'Running') {
        Write-Host "Stopping $ServiceName..."
        Stop-Service -Name $ServiceName -Force
    }
    Write-Host "Deleting service $ServiceName..."
    & sc.exe delete $ServiceName | Out-Host
    # Service deletion is asynchronous — wait a moment so subsequent file removal succeeds.
    Start-Sleep -Seconds 2
} else {
    Write-Host "Service $ServiceName not registered (nothing to delete)."
}

if (Test-Path $InstallPath) {
    if ($KeepConfig) {
        $configFile = Join-Path $InstallPath 'appsettings.Production.json'
        $backup = $null
        if (Test-Path $configFile) {
            $backup = Join-Path $env:TEMP "appsettings.Production.$((Get-Date).Ticks).json"
            Copy-Item $configFile $backup
        }
        Remove-Item $InstallPath -Recurse -Force
        New-Item -ItemType Directory -Path $InstallPath | Out-Null
        if ($backup) {
            Move-Item $backup (Join-Path $InstallPath 'appsettings.Production.json')
            Write-Host "Preserved $configFile"
        }
    } else {
        Remove-Item $InstallPath -Recurse -Force
        Write-Host "Removed $InstallPath"
    }
} else {
    Write-Host "$InstallPath does not exist — nothing to remove."
}

Write-Host "Uninstall complete." -ForegroundColor Green
```

- [ ] **Step 2: Syntax check**

```powershell
cd bridge\windows
$null = [System.Management.Automation.Language.Parser]::ParseFile(
    (Resolve-Path .\Uninstall-PartnerBridge.ps1),
    [ref]$null, [ref]$null)
"OK"
```

Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add bridge/windows/Uninstall-PartnerBridge.ps1
git commit -m "feat(bridge): add Uninstall-PartnerBridge.ps1"
```

---

### Task 10: `bridge/windows/README.md` and `validate.md`

**Files:**
- Create: `bridge/windows/README.md`
- Create: `bridge/windows/validate.md`

- [ ] **Step 1: Create README**

`bridge/windows/README.md`:

````markdown
# Partner365 Bridge — Windows Service install

Alternative to the Docker deployment documented in `../dev/validate.md`. Use this
when Docker isn't available or desirable — typically on-prem Windows servers
that already host Partner365 under `composer run dev` or Windows-hosted
frankenphp.

The bridge exposes the same HTTP API in either mode (`/v1/sites/label`,
`/v1/sites/label:read`, `/health`), so Partner365 cannot tell which it's
talking to.

## Prerequisites

- Windows Server 2019+ or Windows 11.
- .NET SDK 9.0+ installed (`dotnet --version` prints 9.x).
- Elevated PowerShell session.
- Either:
  - A PFX file with the bridge cert + its password, OR
  - A cert already imported into `LocalMachine\My` with a private key.
- Tenant ID, Client ID, and SharePoint admin URL from the Entra app
  registration that Partner365 uses. The app reg must have SharePoint
  `Sites.FullControl.All` and Graph `Sites.FullControl.All` consented.

## Install (first run)

```powershell
cd C:\GitHub\partner365\bridge\windows

# Generate a shared secret (save it — you'll paste it into Partner365 Sweep Config)
$secret = -join ((1..64) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })

# Install
.\Install-PartnerBridge.ps1 `
    -TenantId         'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' `
    -ClientId         'yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy' `
    -AdminSiteUrl     'https://contoso-admin.sharepoint.us' `
    -CloudEnvironment 'gcc_high' `
    -CertPath         'C:\certs\bridge.pfx' `
    -CertPassword     'pfx-password' `
    -SharedSecret     $secret
```

Alternative with a cert already in the Windows store:

```powershell
.\Install-PartnerBridge.ps1 `
    -TenantId         '...' `
    -ClientId         '...' `
    -AdminSiteUrl     'https://contoso-admin.sharepoint.com' `
    -CertThumbprint   'AABBCCDD...' `
    -SharedSecret     $secret
```

On success the script prints the listen URL, cert thumbprint, and shared secret
length. Paste the secret into Partner365's Sweep Config page under "Shared secret"
and Save.

## Update

Re-run `Install-PartnerBridge.ps1` with no credential parameters. The script
preserves existing secrets and only replaces the runtime binaries:

```powershell
.\Install-PartnerBridge.ps1
```

To rotate the shared secret:

```powershell
.\Install-PartnerBridge.ps1 -SharedSecret $newSecret
# Then update Partner365 Sweep Config with the new value.
```

## Uninstall

```powershell
.\Uninstall-PartnerBridge.ps1            # removes service + install dir
.\Uninstall-PartnerBridge.ps1 -KeepConfig # removes only binaries; keeps appsettings.Production.json
```

## Where things live

| Path | Purpose |
|---|---|
| `C:\Partner365Bridge\Partner365.Bridge.exe` | Self-contained binary (no host .NET needed) |
| `C:\Partner365Bridge\appsettings.Production.json` | Admin-editable config (ACL'd: SYSTEM read, Admins full) |
| Windows Service `PartnerBridge` | Delayed-auto start, LocalSystem account, restart-on-failure |
| Application event log, source `PartnerBridge` | Warning+ events (ASP.NET default for Windows Services) |

## Running under a non-LocalSystem account

Default install runs as `LocalSystem`. To switch to a domain account or gMSA:

```powershell
sc.exe config PartnerBridge obj= "DOMAIN\svc-partner365" password= "..."
```

Then grant that account **Read** on the cert's private key file:

```powershell
$cert = Get-ChildItem Cert:\LocalMachine\My\<thumbprint>
$keyPath = "$env:ProgramData\Microsoft\Crypto\RSA\MachineKeys\$($cert.PrivateKey.CspKeyContainerInfo.UniqueKeyContainerName)"
$acl = Get-Acl $keyPath
$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule("DOMAIN\svc-partner365","Read","Allow")))
Set-Acl -Path $keyPath -AclObject $acl
```

Also grant **Read** on `C:\Partner365Bridge\appsettings.Production.json`:

```powershell
icacls C:\Partner365Bridge\appsettings.Production.json /grant:r "DOMAIN\svc-partner365:(R)"
```

## Manual validation harness

See `validate.md` for the end-to-end smoke test against a real tenant.
````

- [ ] **Step 2: Create validate.md**

`bridge/windows/validate.md`:

````markdown
# Partner365 Bridge — Windows Service manual validation

Mirrors `../dev/validate.md` (Docker path). Use when verifying a fresh install,
rotating certs, or confirming Partner365 ↔ bridge integration on Windows.

## Prereqs

- Install completed via `Install-PartnerBridge.ps1`; service `PartnerBridge` is Running.
- Partner365 reachable at http://127.0.0.1:8000 (or wherever it's hosted locally).
- Shared secret from the install step pasted into Partner365 Sweep Config page and saved.

## Steps

1. **Health check:**
    ```powershell
    Invoke-RestMethod http://127.0.0.1:5300/health
    ```
    Expected: `status=ok`, `cloudEnvironment` matches your install parameter, non-null `certThumbprint`.

2. **Authoritative label read via Partner365 tinker:**
    ```powershell
    # Commercial: https://<tenant>.sharepoint.com/sites/<TestSite>
    # GCC High:   https://<tenant>.sharepoint.us/sites/<TestSite>
    cd C:\GitHub\partner365
    php artisan tinker --execute="echo app(App\Services\BridgeClient::class)->readLabel('https://<tenant>.sharepoint.com/sites/<TestSite>');"
    ```
    Cross-check against the SharePoint admin center's "Sensitivity" column for the same site.

3. **Manual apply via Partner365 UI:**
    - Log in as admin, navigate to the test site's detail page.
    - Click "Change label", pick a label, confirm.
    - Within ~30 seconds SharePoint admin center shows the new label.

4. **Dry-run sweep:**
    ```powershell
    php artisan sensitivity:sweep --force --dry-run
    ```
    In Sweep History, the run shows every scanned site with `action=applied` and `[dry-run] would apply` in the error column. No SharePoint changes.

5. **Live sweep:**
    - Save a rule on the Sweep Config page.
    - `php artisan sensitivity:sweep --force`
    - Sweep History shows the run with `applied > 0` once all per-site jobs settle.

6. **Systemic-failure abort:**
    - Swap to a thumbprint the app reg doesn't know:
      ```powershell
      .\Install-PartnerBridge.ps1 -CertThumbprint <unknown-but-valid-in-store>
      ```
    - `php artisan sensitivity:sweep --force`
    - Run transitions to `status=aborted` after 3 systemic auth failures, and admin receives `SweepAbortedNotification`.

7. **Restore:**
    ```powershell
    .\Install-PartnerBridge.ps1 -CertThumbprint <correct-thumbprint>
    # or pass -CertPath back to the known-good PFX
    ```

## Event log inspection

```powershell
Get-WinEvent -LogName Application -MaxEvents 50 |
    Where-Object { $_.ProviderName -like '*PartnerBridge*' } |
    Select-Object TimeCreated, LevelDisplayName, Message |
    Format-List
```

Note: .NET Windows Service logging is Warning+ by default in the event log.
`LogInformation` calls in the bridge (sweep start, label set, etc.) do NOT
appear there. To see them, either:

- Run interactively (`dotnet run` in `bridge\Partner365.Bridge\`), or
- Raise the minimum log level — add to `appsettings.Production.json`:
  ```json
  "Logging": {
    "EventLog": {
      "LogLevel": { "Default": "Information" }
    }
  }
  ```
````

- [ ] **Step 3: Commit**

```bash
git add bridge/windows/README.md bridge/windows/validate.md
git commit -m "docs(bridge): add Windows Service README and validation harness"
```

---

## Phase 5 — Docs updates

### Task 11: Update `docs/admin/sensitivity-labels-sidecar-setup.md`

**Files:**
- Modify: `docs/admin/sensitivity-labels-sidecar-setup.md`

Add a "Choose your deployment mode" section at the top; split the existing Docker-specific content under a "Docker" subheading; add a parallel "Windows Service" section.

- [ ] **Step 1: Read current file to find the right insertion points**

```bash
head -50 docs/admin/sensitivity-labels-sidecar-setup.md
```

Identify the heading under which the current docker compose instructions live (likely "Step 4 — Bring the stack up" or similar).

- [ ] **Step 2: Prepend a deployment-mode picker after the Overview/Prereqs**

Insert after the "Prerequisites" section, before "Step 1":

```markdown
## Choose your deployment mode

The bridge ships as a single binary but supports two deployment models:

| Mode | When to use | Follow |
|---|---|---|
| **Docker container** | You already run Partner365 via `docker compose` on Linux or WSL2; you want config via env vars; you treat the bridge as ephemeral. | Steps 1–7 below (unchanged). |
| **Windows Service** | Your host is Windows; you don't run Docker; you want a service in SCM that survives reboots with delayed auto-start. | See [bridge/windows/README.md](../../bridge/windows/README.md). |

Both modes use the same Entra app registration, same certificate, same shared secret. You can switch between them — just uninstall one before installing the other.

The remainder of this page documents the Docker path. Windows Service-specific setup and troubleshooting live under `bridge/windows/`.

---
```

- [ ] **Step 3: Append a Windows Service appendix before any final "Troubleshooting" section**

Near the end of the file (before any concluding "See also" or references), append:

```markdown
## Appendix: Windows Service alternative

If Docker is not an option, install the bridge as a Windows Service instead:

```powershell
cd C:\GitHub\partner365\bridge\windows
$secret = -join ((1..64) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
.\Install-PartnerBridge.ps1 `
    -TenantId         '<guid>' `
    -ClientId         '<guid>' `
    -AdminSiteUrl     'https://<tenant>-admin.sharepoint.com' `
    -CloudEnvironment 'commercial' `
    -CertPath         'C:\certs\bridge.pfx' `
    -CertPassword     '<pfx-password>' `
    -SharedSecret     $secret
```

The bridge will listen on `http://127.0.0.1:5300`. Set Partner365's Sweep Config page to point at that URL and paste the shared secret. Full documentation, including cert-store thumbprint support, non-LocalSystem service accounts, and the validation harness, lives in `bridge/windows/README.md` and `bridge/windows/validate.md`.
```

- [ ] **Step 4: Commit**

```bash
git add docs/admin/sensitivity-labels-sidecar-setup.md
git commit -m "docs: add Windows Service section to sidecar setup guide"
```

---

### Task 12: Minor docs updates (`CLAUDE.md`, `bridge/dev/validate.md`)

**Files:**
- Modify: `CLAUDE.md`
- Modify: `bridge/dev/validate.md`

- [ ] **Step 1: Read current CLAUDE.md to find the Docker section**

```bash
grep -n "Docker" CLAUDE.md | head -10
```

- [ ] **Step 2: Add a one-line cross-reference under the Docker section**

Find the CLAUDE.md line or paragraph that describes the Docker deployment of the bridge (the one introduced by the previous PR). Append immediately after it:

```markdown
The bridge also ships as a Windows Service for on-prem Windows hosts — see `bridge/windows/README.md` for install.
```

- [ ] **Step 3: Add a cross-reference in `bridge/dev/validate.md`**

Open `bridge/dev/validate.md`. Append at the very top (after the `# Bridge manual validation harness` heading):

```markdown
> **Note:** This file covers the Docker deployment. For Windows Service installs, see `../windows/validate.md`.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md bridge/dev/validate.md
git commit -m "docs: cross-reference Windows Service install from Docker docs"
```

---

## Phase 6 — Final verification

### Task 13: Full test + build sweep

- [ ] **Step 1: Run all bridge unit and integration tests**

```bash
cd bridge && dotnet test
```

Expected: 100% pass. Baseline before this plan was ~54 tests; after: ~67+.

- [ ] **Step 2: Run all Partner365 tests (verify no regressions)**

```bash
cd .. && php artisan test --compact
```

Expected: same pass count as on `main` before this plan (409 passing, 66 pre-existing failures on main). None of our bridge changes touch PHP code — failures should match exactly.

- [ ] **Step 3: Build the Docker image (verify Docker path still works)**

```bash
docker compose build bridge
```

Expected: build succeeds. Image is tagged `partner365-bridge:latest`.

- [ ] **Step 4: Smoke-test the Docker image against the test harness**

```bash
docker run --rm \
    -e BRIDGE_CLOUD_ENVIRONMENT=commercial \
    -e BRIDGE_TENANT_ID=11111111-1111-1111-1111-111111111111 \
    -e BRIDGE_CLIENT_ID=22222222-2222-2222-2222-222222222222 \
    -e BRIDGE_ADMIN_SITE_URL=https://test-admin.sharepoint.com \
    -e BRIDGE_CERT_PATH=__TEST__ \
    -e BRIDGE_SHARED_SECRET=smoke \
    -p 8080:8080 \
    partner365-bridge:latest &

sleep 2
curl -s http://localhost:8080/health
```

Expected: `{"status":"ok","cloudEnvironment":"commercial","certThumbprint":null}`. `docker kill` the process afterward.

This confirms the Docker path continues to work with the new `BridgeOptions` / `LegacyEnvVarMapper` plumbing.

- [ ] **Step 5: Syntax-check PowerShell scripts**

```powershell
cd bridge\windows
Get-ChildItem *.ps1 | ForEach-Object {
    $null = [System.Management.Automation.Language.Parser]::ParseFile(
        (Resolve-Path $_).Path, [ref]$null, [ref]$null)
    "$($_.Name): OK"
}
```

Expected: `Install-PartnerBridge.ps1: OK`, `Uninstall-PartnerBridge.ps1: OK`.

- [ ] **Step 6: Manual verification harness (requires real tenant)**

On a Windows host with valid tenant credentials, follow `bridge/windows/validate.md` end to end. This is the only test of the actual Windows Service installation; it cannot run in CI.

- [ ] **Step 7: Final commit if any adjustments needed**

If any test tweak, doc fix, or code adjustment was required during verification:

```bash
git add -A
git commit -m "chore: final verification adjustments"
```

Otherwise this step is a no-op.

---

## Self-review notes

Mapping of spec sections to tasks:

| Spec section | Task(s) |
|---|---|
| Architecture diagram & properties | Reflected throughout; Task 6 wires `UseWindowsService`, `LegacyEnvVarMapper`, `BridgeOptions` |
| Configuration schema (appsettings.json) | Task 6 (Step 1) |
| `BridgeOptions` class + validation | Task 2 |
| Env var aliasing | Task 3 |
| `CertificateLoader.Load` dispatch | Task 4 |
| `LoadFromStore` thumbprint + normalization | Task 5 |
| `Program.cs` rewrite | Task 6 |
| `BridgeFactory` update | Task 7 |
| `Install-PartnerBridge.ps1` | Task 8 |
| `Uninstall-PartnerBridge.ps1` | Task 9 |
| `bridge/windows/README.md` | Task 10 |
| `bridge/windows/validate.md` | Task 10 |
| Deployment guide updates | Task 11 |
| CLAUDE.md + dev/validate.md cross-refs | Task 12 |
| Test coverage (options, mapper, thumbprint) | Tasks 2, 3, 4, 5 |
| Docker path preservation | Tasks 7, 13 Step 3–4 |
| Manual validation harness | Task 10 (file) + Task 13 Step 6 (runbook) |
