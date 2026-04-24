# Bridge manual validation harness

Use this checklist when wiring a new tenant, rotating certs, or verifying a release build.

## Prereqs

- Real M365 tenant (commercial or GCC High).
- App registration with cert credential + SharePoint `Sites.FullControl.All` consented.
- PFX file on disk.
- Partner365 compose stack running.

## Steps

1. `docker compose up -d` (bring up both containers).

2. Health check with secret bypass:
    ```bash
    docker compose exec -T bridge curl -fsS http://localhost:8080/health
    ```
    Expect JSON with `"status":"ok"`, matching `cloudEnvironment`, and a certThumbprint.

3. Authoritative label read via Partner365 `tinker`:
    ```bash
    # Commercial: https://<tenant>.sharepoint.com/sites/<TestSite>
    # GCC High:   https://<tenant>.sharepoint.us/sites/<TestSite>
    docker compose exec app php artisan tinker --execute="echo app(App\Services\BridgeClient::class)->readLabel('https://<tenant>.sharepoint.com/sites/<TestSite>');"
    ```
    Cross-check against the SharePoint admin center's Sensitivity column for the same site.

4. Manual apply via Partner365 UI:
    - Log in as admin, navigate to the test site's page, click "Change label", pick a known label.
    - Confirm SharePoint admin center shows the new label within 30 seconds.

5. Dry-run sweep:
    ```bash
    docker compose exec app php artisan sensitivity:sweep --force --dry-run
    ```
    Confirm a `LabelSweepRun` row exists, with entries for every enumerated site; no sites were actually labeled.

6. Live sweep:
    - Save at least one rule via Sweep Config page.
    - `docker compose exec app php artisan sensitivity:sweep --force`.
    - Sweep Config page shows the fresh run with applied > 0.

7. Systemic-failure abort:
    - Swap `${BRIDGE_CERT_HOST_PATH}` to a throwaway cert the app reg doesn't know about.
    - `docker compose restart bridge`.
    - `docker compose exec app php artisan sensitivity:sweep --force`.
    - Expect the run to transition to `status=aborted` after 3 systemic failures, and a `SweepAbortedNotification` in the admin's inbox.
