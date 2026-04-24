# Sensitivity labels sidecar setup

This page walks a Partner365 administrator through enabling the sensitivity-labels sweep feature, which uses a companion .NET sidecar (`partner365-bridge`) to write labels to SharePoint sites. The sidecar exists because Microsoft Graph cannot set sensitivity labels on SharePoint sites in GCC High (and is unreliable in commercial), so CSOM is the only viable write path.

## Prerequisites

- You are running Partner365 via `docker compose` from this repo.
- You have tenant administrator access to the Entra portal.
- You have Global Admin (or Application Administrator + SharePoint Administrator) available to consent new permissions.
- `openssl` and `PowerShell 7+` are available locally.

## Step 1 — Generate a certificate for the bridge

On Windows (elevated PowerShell):

```powershell
$cert = New-SelfSignedCertificate `
    -Subject "CN=Partner365-Bridge" `
    -KeyAlgorithm RSA -KeyLength 2048 `
    -CertStoreLocation "Cert:\CurrentUser\My" `
    -NotAfter (Get-Date).AddYears(2) `
    -KeyExportPolicy Exportable `
    -KeySpec Signature

# Export the public cer for upload to Entra
Export-Certificate -Cert $cert -FilePath .\bridge.cer

# Export the pfx for the container
$pw = ConvertTo-SecureString -String "CHOOSE-A-PASSWORD" -Force -AsPlainText
Export-PfxCertificate -Cert $cert -FilePath .\bridge.pfx -Password $pw
```

Copy the resulting `bridge.pfx` to `./storage/bridge/bridge.pfx` (or wherever you plan to point `BRIDGE_CERT_HOST_PATH`). Keep the password — you'll put it in `.env`.

## Step 2 — Add credentials and permissions to the Partner365 app registration

In the Entra admin portal, find the existing Partner365 app registration (the one matching `MICROSOFT_GRAPH_CLIENT_ID`).

1. **Upload the certificate:** Certificates & secrets → Certificates → Upload certificate → pick `bridge.cer`. Keep the existing client secret — Partner365's Graph path still uses it.

2. **Add API permissions:**
   - Microsoft Graph → Application permissions → `Sites.FullControl.All` — if not already present.
   - Office 365 SharePoint Online → Application permissions → `Sites.FullControl.All` — required for CSOM tenant admin.

3. **Grant admin consent** for both. Both rows should show a green check next to the tenant name.

## Step 3 — Populate `.env`

Copy `.env.example` to `.env` (if you haven't already) and set:

```
MICROSOFT_GRAPH_CLOUD_ENVIRONMENT=commercial     # or gcc-high
MICROSOFT_GRAPH_TENANT_ID=<your tenant guid>
MICROSOFT_GRAPH_CLIENT_ID=<existing partner365 app reg id>

SHAREPOINT_ADMIN_SITE_URL=https://<tenant>-admin.sharepoint.com   # or .sharepoint.us for GCC High
BRIDGE_CERT_HOST_PATH=./storage/bridge/bridge.pfx
BRIDGE_CERT_PASSWORD=<pfx password from Step 1>
BRIDGE_SHARED_SECRET=<run `openssl rand -hex 32`>
```

## Step 4 — Bring the stack up

```bash
docker compose up -d --build
```

Watch bridge startup:

```bash
docker compose logs -f bridge
```

Expected first lines: cert thumbprint, cloud environment, Kestrel bind. If the bridge fails here, check:
- `BRIDGE_CERT_PATH` is reachable inside the container.
- Cert password is correct.
- `BRIDGE_ADMIN_SITE_URL` contains `-admin.` (the bridge will refuse to start otherwise).

## Step 5 — Verify in Partner365

1. Log in as an admin.
2. Sidebar → Sweep Config.
3. The bridge indicator at the top should be green with the correct cloud environment.
4. Set **Default label** to a label from your tenant's catalog.
5. Leave the rules and exclusions empty for the first test.
6. Save.

## Step 6 — First dry-run

```bash
docker compose exec app php artisan sensitivity:sweep --force --dry-run
```

Open Sweep history. The run should show `status=success`, every scanned site listed with `action=applied` and the message `[dry-run] would apply` in the error column. No site in SharePoint was actually relabeled.

## Step 7 — First live sweep

1. Sweep Config page → set **Enabled** to on. Save.
2. Wait for the scheduled run or force one:
    ```bash
    docker compose exec app php artisan sensitivity:sweep --force
    ```
3. First live sweep takes **10–30 minutes** against ~2000 sites. Subsequent sweeps hit the fast-path and finish in a couple of minutes.
4. Spot-check two sites in SharePoint admin center → Active sites, toggle on the "Sensitivity" column. Newly-applied labels should appear.

## Troubleshooting

| Symptom | Most likely cause | Fix |
|---|---|---|
| Bridge indicator red, "unreachable" | Container not running | `docker compose ps`, check `docker compose logs bridge` for startup error |
| Sweep run `failed` immediately with "Bridge pre-flight failed" | Secret mismatch between .env and Partner365 settings | Open Sweep config → re-save to sync `BRIDGE_SHARED_SECRET` from env into settings |
| Many entries with `error_code=auth` | Cert or consent problem | Re-verify both `Sites.FullControl.All` grants are consented in Entra |
| Run aborts after exactly 3 failures | Systemic-failure abort fired | Check admin inbox for `SweepAbortedNotification`; fix the cert/consent issue and re-run |
| Sweep applies correctly but UI says "No label" after | Partner365's cached `SharePointSite.sensitivity_label_id` is stale | On site detail page, click **Refresh from SharePoint** |

## Secret rotation

1. Generate new shared secret (`openssl rand -hex 32`).
2. Update `BRIDGE_SHARED_SECRET` in `.env` → `docker compose up -d bridge` to restart bridge with the new value.
3. In Sweep Config → paste new secret into "Shared secret" → Save.
4. Trigger a test sweep to confirm.

Brief overlap is fine — in-flight jobs will retry with backoff.

## Rollback

- Disable the feature: Sweep Config → turn off Enabled → Save. Existing history stays; no new sweeps run.
- Remove bridge: comment out the `bridge` service in `docker-compose.yml`, `docker compose up -d`. Manual-apply buttons will fail with a user-friendly "sidecar not reachable" message. Scheduled command will fail at pre-flight health check. No labels in M365 are rolled back — they stay as they were set.
