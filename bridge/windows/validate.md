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
