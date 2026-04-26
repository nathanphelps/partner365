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

### Sensitivity-label enumeration prerequisites (`GET /v1/labels`)

The bridge's `GET /v1/labels` endpoint runs `Get-Label` via Exchange Online
PowerShell in-process. One-time host setup as the bridge's service
account:

```powershell
Install-Module ExchangeOnlineManagement -Scope AllUsers -Force -RequiredVersion 3.5.1
```

The AAD app registration (the same one used for SharePoint CSOM) needs:

- Application permission `Office 365 Exchange Online → Exchange.ManageAsApp` (admin consent required)
- Directory role assignment on the service principal: `Compliance Data Administrator` (read-only) or `Compliance Administrator`
- The cert must be in `LocalMachine\My` with a thumbprint set on the bridge config (`-CertThumbprint`). `Connect-IPPSSession`'s cert auth requires a thumbprint, not a PFX path.

The bridge reuses its existing certificate and tenant/client config — no
new credentials.

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
