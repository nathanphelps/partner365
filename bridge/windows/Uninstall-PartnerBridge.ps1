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
