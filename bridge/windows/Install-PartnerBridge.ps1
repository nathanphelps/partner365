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
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
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
# For parameters with script-level defaults (CloudEnvironment, ListenUrl), use
# $PSBoundParameters.ContainsKey so the default doesn't silently overwrite an
# existing setting on idempotent re-run (e.g. GCC High install flipping back
# to "commercial", or custom port resetting to 5300).
$final = [ordered]@{
    CloudEnvironment = if ($PSBoundParameters.ContainsKey('CloudEnvironment')) { $CloudEnvironment } `
                       elseif ($existing.Bridge.CloudEnvironment) { $existing.Bridge.CloudEnvironment } `
                       else { $CloudEnvironment }
    TenantId         = if ($TenantId)         { $TenantId }         elseif ($existing.Bridge.TenantId)         { $existing.Bridge.TenantId }         else { $null }
    ClientId         = if ($ClientId)         { $ClientId }         elseif ($existing.Bridge.ClientId)         { $existing.Bridge.ClientId }         else { $null }
    AdminSiteUrl     = if ($AdminSiteUrl)     { $AdminSiteUrl }     elseif ($existing.Bridge.AdminSiteUrl)     { $existing.Bridge.AdminSiteUrl }     else { $null }
    SharedSecret     = if ($SharedSecret)     { $SharedSecret }     elseif ($existing.Bridge.SharedSecret)     { $existing.Bridge.SharedSecret }     else { $null }
    ListenUrl        = if ($PSBoundParameters.ContainsKey('ListenUrl')) { $ListenUrl } `
                       elseif ($existing.Bridge.ListenUrl) { $existing.Bridge.ListenUrl } `
                       else { $ListenUrl }
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
# $ErrorActionPreference='Stop' does NOT trap native-exe nonzero exits, so check
# $LASTEXITCODE explicitly. If inheritance removal fails silently (file locked,
# policy, AV handle) the file retains Users:Read from its parent — exposing the
# shared secret to any local user.
& icacls $configFile /inheritance:r | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "icacls /inheritance:r failed with exit $LASTEXITCODE. Config file may still be world-readable at $configFile."
}
& icacls $configFile /grant:r "NT AUTHORITY\SYSTEM:(R)" "BUILTIN\Administrators:(F)" | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "icacls /grant failed with exit $LASTEXITCODE. Config file ACL is in an unknown state at $configFile."
}

# Defensive verification: confirm no Users / Everyone / Authenticated Users allow
# ACE survived the inheritance removal. If one did, refuse to start the service —
# leaving the secret readable to all local users is not an acceptable install.
$acl = (Get-Acl $configFile).Access
$leaks = $acl | Where-Object {
    $_.AccessControlType -eq 'Allow' -and
    $_.IdentityReference -match '(Users|Everyone|Authenticated Users|INTERACTIVE)'
}
if ($leaks) {
    $leakNames = ($leaks | ForEach-Object { $_.IdentityReference.Value }) -join ', '
    throw "ACL verification failed: $configFile still grants read to: $leakNames. Install aborted to protect the shared secret."
}
Write-Host "ACL'd $configFile (SYSTEM read, Administrators full, no inheritance, verified clean)."

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
