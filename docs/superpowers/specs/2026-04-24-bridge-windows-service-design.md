# Bridge Windows Service — Design Spec

**Date:** 2026-04-24
**Status:** Draft

## Overview

Adds a first-class Windows Service deployment path for `partner365-bridge` alongside the existing Linux container path. Both modes ship from the same codebase and use the same runtime services; they differ only in where configuration comes from, where the certificate is loaded from, and how the process is hosted. Partner365 admins who can't (or prefer not to) run Docker — e.g., on-prem Windows hosts already running Label365 — can install the bridge as a Windows Service via a PowerShell script.

### Goals

- Single codebase: no `#if WINDOWS` branches; the Docker image and the Windows Service publish the same binary with different hosting + config sources.
- Run as a Windows Service on Windows Server 2019+ / Windows 11 using `Host.UseWindowsService()`.
- Ship PowerShell install / uninstall scripts that publish, copy, register, and start the service idempotently.
- Support both PFX-file and Windows-cert-store-thumbprint certificate sources.
- Preserve the existing Docker deployment surface exactly — `docker-compose.yml`, `Dockerfile`, `validate.md`, and all existing env var names keep working without changes on the admin side.

### Non-goals

- MSI installer, GUI installer, or Windows Installer service. PowerShell scripts are enough.
- gMSA / managed service account support in the install script. Service runs as `LocalSystem` by default; admins can manually change the run-as account and configure cert ACLs, same as Label365 documents today.
- Auto-update / self-update. Install script's re-run path is the update path.
- Binding on a non-loopback address. Windows Service bind is `http://127.0.0.1:5300` by default; admins can override in `appsettings.Production.json` but network exposure beyond localhost is out of scope.
- Label365 coexistence shim. They're separate products; admins pick one or the other.

## Architecture

One binary, two deployment modes, shared service registrations:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Partner365.Bridge (.NET 9)                           │
│                                                                             │
│   ConfigurationBuilder merges (in order):                                   │
│     1. appsettings.json              (shipped defaults, in publish output)  │
│     2. appsettings.{Environment}.json (admin-editable, not in git)          │
│     3. Environment variables (BRIDGE_*)  (docker-compose path)              │
│                                                                             │
│   CertificateLoader picks source:                                           │
│     • CertThumbprint set? → LocalMachine\My  (Windows Service typical)      │
│     • CertPath set?       → PFX file on disk (Docker typical)               │
│     • Neither set?        → fail-loud at boot                               │
│                                                                             │
│   Host.UseWindowsService()  — no-op on Linux, activates when launched as    │
│   a service on Windows (via sc.exe); plain console when launched by user    │
└─────────────────────────────────────────────────────────────────────────────┘

       ↓ ONE binary — ships in both forms                           ↓

   ┌──────────────────────────────────┐     ┌────────────────────────────────┐
   │  Docker (existing, unchanged)    │     │  Windows Service (new)         │
   │                                  │     │                                │
   │  Dockerfile builds with          │     │  Install-PartnerBridge.ps1:    │
   │    dotnet publish -c Release     │     │    dotnet publish -c Release   │
   │                                  │     │      -r win-x64                │
   │  Container binds                 │     │      --self-contained true     │
   │  http://0.0.0.0:8080             │     │    copy to C:\Partner365Bridge │
   │  (configurable via env)          │     │    write appsettings.Production│
   │                                  │     │    sc.exe create + start       │
   │  Config: env vars from           │     │                                │
   │  docker-compose                  │     │  Binds http://127.0.0.1:5300   │
   │                                  │     │  (loopback-only by default)    │
   │  Cert: /run/secrets/bridge.pfx   │     │                                │
   │                                  │     │  Config: appsettings.          │
   │                                  │     │    Production.json (ACL'd)     │
   │                                  │     │                                │
   │                                  │     │  Cert: thumbprint OR PFX path  │
   │                                  │     │                                │
   │  Partner365 reaches              │     │  Partner365 reaches            │
   │  http://bridge:8080              │     │  http://127.0.0.1:5300         │
   └──────────────────────────────────┘     └────────────────────────────────┘
```

### Load-bearing properties

- **One binary.** `Host.UseWindowsService()` is a no-op when the process isn't launched by the Service Control Manager — so the same published binary runs as a Linux container, a console on a Windows dev box, and a Windows Service, without any branching.
- **Config merge, not config swap.** `.NET Configuration` reads `appsettings*.json` AND env vars and merges them. Docker admins still use env vars; Windows admins use the JSON file; mixing works.
- **Docker path frozen.** The existing `docker-compose.yml`, `Dockerfile`, and all `BRIDGE_*` env vars keep working byte-for-byte. The JSON file is additive.
- **Windows Service loopback-only.** `127.0.0.1:5300` by default — Partner365 on the same host can reach it via loopback without firewall changes; nothing off-host can reach it unless an admin explicitly changes `ListenUrl`.

## Configuration schema

### `appsettings.json` (shipped in publish output)

Defaults only — everything tenant-specific lives in the Production file.

```json
{
  "Logging": {
    "LogLevel": { "Default": "Information", "Microsoft.AspNetCore": "Warning" }
  },
  "Bridge": {
    "ListenUrl": "http://127.0.0.1:5300"
  }
}
```

### `appsettings.Production.json` (written by install script, admin-editable)

```json
{
  "Bridge": {
    "CloudEnvironment": "gcc_high",
    "TenantId": "...",
    "ClientId": "...",
    "AdminSiteUrl": "https://gdotsdevtest-admin.sharepoint.us",
    "CertPath": "C:/certs/partner365-compliance.pfx",
    "CertPassword": "...",
    "CertThumbprint": null,
    "SharedSecret": "...",
    "ListenUrl": "http://127.0.0.1:5300"
  }
}
```

Not committed to git. Admin may edit directly to rotate secrets or change the port. File is ACL'd so only `LocalSystem` + `Administrators` can read.

### Docker env var aliasing

`docker-compose.yml` continues to set flat env vars. `Program.cs` at startup normalizes legacy flat names into the `Bridge:*` section before the options binding runs:

```csharp
static void MapLegacyEnvVars()
{
    (string flat, string key)[] aliases =
    {
        ("BRIDGE_CLOUD_ENVIRONMENT", "Bridge:CloudEnvironment"),
        ("BRIDGE_TENANT_ID",         "Bridge:TenantId"),
        ("BRIDGE_CLIENT_ID",         "Bridge:ClientId"),
        ("BRIDGE_ADMIN_SITE_URL",    "Bridge:AdminSiteUrl"),
        ("BRIDGE_CERT_PATH",         "Bridge:CertPath"),
        ("BRIDGE_CERT_PASSWORD",     "Bridge:CertPassword"),
        ("BRIDGE_CERT_THUMBPRINT",   "Bridge:CertThumbprint"),
        ("BRIDGE_SHARED_SECRET",     "Bridge:SharedSecret"),
    };
    foreach (var (flat, key) in aliases)
    {
        var v = Environment.GetEnvironmentVariable(flat);
        if (!string.IsNullOrWhiteSpace(v))
        {
            // .NET Configuration reads `Section__Key` (double underscore) as nested
            // `Section:Key`. Single underscore is NOT treated as nesting.
            Environment.SetEnvironmentVariable(key.Replace(":", "__"), v);
        }
    }
}
```

Run before `WebApplication.CreateBuilder(args)`. Idempotent; non-destructive (does not overwrite pre-existing `Bridge__*` vars if an admin set them directly).

`ASPNETCORE_URLS` (when set) continues to take precedence over `Bridge:ListenUrl` — this is ASP.NET Core's built-in behavior and we keep it to stay predictable for Docker admins.

### `BridgeOptions` + boot-time validation

```csharp
public sealed class BridgeOptions
{
    [Required] public string CloudEnvironment { get; init; } = "";
    [Required] public string TenantId { get; init; } = "";
    [Required] public string ClientId { get; init; } = "";
    [Required] public string AdminSiteUrl { get; init; } = "";
    [Required] public string SharedSecret { get; init; } = "";
    [Required] public string ListenUrl { get; init; } = "";
    public string? CertPath { get; init; }
    public string? CertPassword { get; init; }
    public string? CertThumbprint { get; init; }

    // Cross-field validation: at least one cert source must be set.
    public IEnumerable<ValidationResult> ValidateCertSource() =>
        string.IsNullOrWhiteSpace(CertThumbprint) && string.IsNullOrWhiteSpace(CertPath)
            ? new[] { new ValidationResult("Bridge:CertThumbprint or Bridge:CertPath must be set.") }
            : Array.Empty<ValidationResult>();
}
```

Registered with:

```csharp
builder.Services.AddOptions<BridgeOptions>()
    .Bind(builder.Configuration.GetSection("Bridge"))
    .ValidateDataAnnotations()
    .Validate(o => !o.ValidateCertSource().Any(), "Cert source validation failed.")
    .ValidateOnStart();
```

Missing required fields fail the host at boot — `docker compose up` exits non-zero and `Start-Service` reports failure in the Windows event log. Same mechanism, same guarantee.

## CertificateLoader update

One method, two paths, keyed by which option field is set:

```csharp
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

    // LoadFromPfx: unchanged from today.

    private static X509Certificate2 LoadFromStore(string thumbprint)
    {
        // MMC's "copy thumbprint" paste often includes U+200E (LTR mark) and spaces.
        // Strip non-alphanumerics and upper-case before comparing.
        var normalized = new string(thumbprint.Where(char.IsLetterOrDigit).ToArray())
            .ToUpperInvariant();

        using var store = new X509Store(StoreName.My, StoreLocation.LocalMachine);
        store.Open(OpenFlags.ReadOnly);
        var matches = store.Certificates.Find(
            X509FindType.FindByThumbprint, normalized, validOnly: false);

        if (matches.Count == 0)
        {
            throw new InvalidOperationException(
                $"Certificate with thumbprint '{normalized}' not found in LocalMachine\\My.");
        }

        var cert = matches[0];
        if (!cert.HasPrivateKey)
        {
            throw new InvalidOperationException(
                $"Certificate '{thumbprint}' has no private key. Re-import the PFX " +
                "(not just the .cer) and ensure the service account can read the key.");
        }

        return cert;
    }
}
```

### Deliberate choices

1. **`LocalMachine\My` only.** `CurrentUser\My` is ambiguous under a service account (which user? depends on LoadUserProfile); forcing `LocalMachine` avoids the "works interactively, fails as service" failure mode Label365 documented.
2. **Thumbprint normalization.** Strip non-alphanumerics before comparing — paste from MMC's "Copy to Clipboard" includes U+200E and spaces. Emit the normalized form in the "not found" error to aid debugging.
3. **Private-key check at startup.** Loading a `.cer` (public only) instead of a `.pfx` (with key) is a common mistake. Diagnose at boot rather than hitting `InvalidCredentials` from `ClientCertificateCredential` on the first CSOM call.
4. **Single code path change.** `Program.cs` calls `CertificateLoader.Load(opts)` — no branching based on deployment mode. Which path is taken depends on which option field is set, and that's admin-visible.

### Service-account note

Documented in install script output and in the deployment guide. Default `LocalSystem` can read `LocalMachine\My` private keys without extra ACLs. If an admin switches to a domain account or gMSA, they must grant the account read on the cert's key file:

```powershell
# Example — grant read to a domain service account
$cert = Get-ChildItem Cert:\LocalMachine\My\<thumbprint>
$keyPath = "$env:ProgramData\Microsoft\Crypto\RSA\MachineKeys\$($cert.PrivateKey.CspKeyContainerInfo.UniqueKeyContainerName)"
$acl = Get-Acl $keyPath
$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule("DOMAIN\svc-partner365","Read","Allow")))
Set-Acl -Path $keyPath -AclObject $acl
```

(Same pattern Label365 uses — lifted into the Partner365 docs.)

## Install / uninstall scripts

Both live under `bridge/windows/` in the repo:

```
bridge/windows/
├── Install-PartnerBridge.ps1
├── Uninstall-PartnerBridge.ps1
└── README.md                    # Quick start: pre-reqs, 3-command install
```

### `Install-PartnerBridge.ps1`

**Parameters** (with sensible defaults):
- `-InstallPath` — default `C:\Partner365Bridge`
- `-ServiceName` — default `PartnerBridge`
- `-DisplayName` — default `Partner365 Bridge`
- `-ListenUrl` — default `http://127.0.0.1:5300`
- `-TenantId`, `-ClientId`, `-AdminSiteUrl`, `-SharedSecret` — required on first install; optional on update (preserved from existing config if not supplied)
- `-CertThumbprint` — optional
- `-CertPath`, `-CertPassword` — optional
- (exactly one of the two cert inputs must be provided on first install; enforced by a parameter-set constraint)
- `-RepoRoot` — default `Split-Path $PSScriptRoot -Parent -Parent`; where the bridge csproj lives

**Flow** (idempotent — re-run is an update):

1. **Pre-flight:** verify elevation, verify `dotnet --version` ≥ 9.0, resolve the `.csproj`, confirm cert source is valid (file exists OR thumbprint present in `LocalMachine\My`).
2. **Stop existing service** if it's running (`Stop-Service -ErrorAction SilentlyContinue`) — no-op on first install.
3. **Publish:** `dotnet publish $RepoRoot\bridge\Partner365.Bridge -c Release -r win-x64 --self-contained true -o $InstallPath`. Self-contained means no host .NET runtime dependency.
4. **Write `appsettings.Production.json`:** generate or merge. If the file exists, load it, overlay any supplied parameters, rewrite. If it doesn't exist, build from scratch. Secrets (`CertPassword`, `SharedSecret`) are stored in cleartext inside the JSON but the file is ACL'd (see step 5).
5. **ACL the config file:** `icacls` removes inherited permissions, grants `LocalSystem:R` and `BUILTIN\Administrators:F`. Matches how Label365 handles its DB file.
6. **Register (or reconfigure) the service** via `sc.exe`:
   ```powershell
   sc.exe create $ServiceName binPath= "$InstallPath\Partner365.Bridge.exe" start= delayed-auto obj= LocalSystem DisplayName= "$DisplayName"
   sc.exe description $ServiceName "Partner365 bridge for SharePoint CSOM sensitivity-label writes."
   sc.exe failure $ServiceName reset= 86400 actions= restart/60000/restart/60000/restart/60000
   ```
   Re-runs call `sc.exe config` instead of `sc.exe create`.
7. **Start the service** and wait for `Running` state with a 30s timeout. On timeout, dump recent Application-log events for `Source=PartnerBridge` to stderr.
8. **Verify health:** `Invoke-RestMethod $ListenUrl/health` with a 5-attempt × 1s backoff loop. Print the parsed response (status, cloudEnvironment, cert thumbprint).

**Output:** prints install path, service name, listen URL, cert source, cert thumbprint, shared-secret *length* (not the secret itself). Enough for the admin to populate Partner365's Sweep Config page and verify parity.

### `Uninstall-PartnerBridge.ps1`

Parameters: `-ServiceName` (default `PartnerBridge`), `-InstallPath` (default `C:\Partner365Bridge`), `-KeepConfig` (switch — default false means wipe `appsettings.Production.json` too).

Flow:
1. `Stop-Service $ServiceName -ErrorAction SilentlyContinue`.
2. `sc.exe delete $ServiceName`.
3. `Remove-Item $InstallPath -Recurse -Force` unless `-KeepConfig` is set, in which case preserve the JSON file and remove only runtime binaries.

### `bridge/windows/README.md`

Short quick-start:

```
# Partner365 Bridge — Windows Service install

## 3-command install (elevated PowerShell)

    cd C:\GitHub\partner365\bridge\windows

    # Generate a shared secret (reuse for Partner365 Sweep Config)
    $secret = -join ((1..64) | % { '{0:x}' -f (Get-Random -Max 16) })

    # Install
    .\Install-PartnerBridge.ps1 `
        -TenantId      '<guid>' `
        -ClientId      '<guid>' `
        -AdminSiteUrl  'https://<tenant>-admin.sharepoint.us' `
        -CertPath      'C:\certs\bridge.pfx' `
        -CertPassword  '<pfx-password>' `
        -SharedSecret  $secret

## Uninstall

    .\Uninstall-PartnerBridge.ps1

## Updating

Re-run `Install-PartnerBridge.ps1` — same script handles updates via idempotent publish.
```

Full operational docs (ACLs, service account changes, firewall notes) live in the existing `docs/admin/sensitivity-labels-sidecar-setup.md`, which gets a Windows Service section.

## Deployment flow

### First install (Windows Service)

1. Admin puts PFX somewhere (or imports into `LocalMachine\My`).
2. Admin opens elevated PowerShell, runs `Install-PartnerBridge.ps1` with tenant/client/cert params.
3. Script publishes, writes config, registers, starts.
4. Script prints "Bridge at http://127.0.0.1:5300 — shared secret: 64 chars".
5. Admin pastes the secret into Partner365's Sweep Config page, saves.
6. Partner365 health check on Sweep Config page goes green.

### Update (Windows Service)

1. `git pull` on the repo.
2. Re-run `Install-PartnerBridge.ps1` (with no credential params).
3. Script stops service → republishes → updates appsettings (preserving existing secrets) → restarts service → verifies health.

### Docker path — unchanged

`docker compose up -d --build` still just works. No script, no repository changes for existing Docker users.

## Testing

### Unit tests

New tests under `Partner365.Bridge.Tests`:

- `CertificateLoaderThumbprintTests` — thumbprint normalization (spaces, U+200E), thumbprint-not-found error message, private-key-less cert error. Uses an in-memory `X509Store` fixture (`StoreLocation.CurrentUser` — the test runs in-process, we're not validating `LocalMachine` store access; the store-kind is already enforced in production code).
- `BridgeOptionsValidationTests` — missing TenantId fails, missing both cert sources fails with helpful message, thumbprint-only passes, path+password passes.
- `MapLegacyEnvVarsTests` — env var → config key aliasing table-driven; verifies double-underscore form lands in the right `Bridge:*` key; existing `Bridge__*` env vars not overwritten.

### Integration tests

Existing `BridgeStartupTests` already uses `BridgeFactory` with `__TEST__` cert sentinel. No changes needed — the sentinel path keeps working.

### Manual verification (Windows)

Checked into `bridge/windows/validate.md`, mirrors the existing `bridge/dev/validate.md`:

1. Run `Install-PartnerBridge.ps1` with real params.
2. `Invoke-RestMethod http://127.0.0.1:5300/health` — expect `ok`, correct cloud env, matching thumbprint.
3. In Partner365: paste secret → save → health pill goes green.
4. `docker compose exec app php artisan tinker --execute="echo app(App\Services\BridgeClient::class)->readLabel('https://<tenant>.sharepoint.us/sites/<Test>');"` (same tinker command, just going to loopback instead of `bridge:8080`).
5. Stop service with `Stop-Service PartnerBridge`. Sweep Config health pill goes red. Restart, goes green.
6. Trigger sweep with `--force`; verify label appears in SharePoint admin center within 30s.

## Docs updates

- `docs/admin/sensitivity-labels-sidecar-setup.md` — add top-level "Pick your deployment mode" section with two tabs: Docker (existing content) and Windows Service (new — link to `bridge/windows/README.md` for quick start, summarize appsettings schema inline, note cert-store option).
- `CLAUDE.md` — one line under Docker section: "The bridge also supports a Windows Service install; see `bridge/windows/`."
- `bridge/dev/validate.md` — add cross-reference to `bridge/windows/validate.md`.
- `.env.example` — keep as-is. It's for Docker; Windows Service admins use params to the install script.

## What this spec explicitly does NOT change

- The bridge's HTTP API (`/v1/sites/label`, `/v1/sites/label:read`, `/health`).
- The shared-secret auth mechanism.
- `SiteUrlValidator` tenant-scoping.
- PnP.Framework dependency version or CSOM behavior.
- Partner365-side code: `BridgeClient`, `SensitivitySweepCommand`, UI pages, routes.
- `docker-compose.yml` or the `Dockerfile`.

The bridge is the only project touched. Everything upstream consumes the same HTTP contract and cannot tell whether it's talking to a container or a Windows Service.

## Open questions

None.
