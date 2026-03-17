$ErrorActionPreference = 'Stop'
Import-Module ExchangeOnlineManagement
Write-Output "Module version: $((Get-Module ExchangeOnlineManagement).Version.ToString())"

$certPassword = ConvertTo-SecureString -String $env:PS_CERT_PASSWORD -AsPlainText -Force
$cert = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($env:PS_CERT_PATH, $certPassword)
Write-Output "Cert Subject: $($cert.Subject)"
Write-Output "Cert Thumbprint: $($cert.Thumbprint)"
Write-Output "Cert HasPrivateKey: $($cert.HasPrivateKey)"

Write-Output ""
Write-Output "Connecting to IPPS..."
Write-Output "  AppId: $($env:PS_CLIENT_ID)"
Write-Output "  Organization: $($env:PS_ORGANIZATION)"
Write-Output "  ConnectionUri: $($env:PS_CONNECTION_URI)"
Write-Output "  AzureADUri: $($env:PS_AZURE_AD_URI)"
Write-Output ""

try {
    Connect-IPPSSession `
        -AppId $env:PS_CLIENT_ID `
        -CertificateFilePath $env:PS_CERT_PATH `
        -CertificatePassword $certPassword `
        -Organization $env:PS_ORGANIZATION `
        -ConnectionUri $env:PS_CONNECTION_URI `
        -AzureADAuthorizationEndpointUri $env:PS_AZURE_AD_URI
    Write-Output "Connected!"
    $labels = Get-Label
    Write-Output "Found $($labels.Count) labels"
    $labels | Select-Object -First 3 DisplayName, ImmutableId | Format-Table
    Disconnect-ExchangeOnline -Confirm:$false
} catch {
    Write-Output "ERROR: $($_.Exception.Message)"
    Write-Output "Type: $($_.Exception.GetType().FullName)"
    if ($_.Exception.InnerException) {
        Write-Output "Inner: $($_.Exception.InnerException.Message)"
    }
}
