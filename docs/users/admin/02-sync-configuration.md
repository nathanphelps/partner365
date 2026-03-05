---
title: Sync Configuration
admin: true
---

# Sync Configuration

The Admin > Sync page lets you monitor and configure the background data synchronization that keeps Partner365 in step with your Microsoft Entra ID tenant.
Sync ensures that changes made outside Partner365 — whether in the Entra admin center, via PowerShell, or by other tools — are reflected in the application.

## How Sync Works

Partner365 uses a write-through plus background reconciliation pattern for data management:

1. **Write-through** — When you make a change in Partner365 (add a partner, invite a guest, modify a policy), the application writes to the [Graph API](/docs/glossary/glossary) immediately and updates the local database in the same operation.
   You see the result right away.
2. **Background reconciliation** — Sync jobs run on a configurable interval to pull the latest state from Entra ID and update the local database.
   This catches any changes made outside Partner365 and corrects any drift between the local database and the authoritative source (Entra ID).

This dual approach means your actions are reflected instantly while the system also self-corrects on a regular schedule.

> **Good to know:** Sync only affects the local database — it reads from the Graph API and updates Partner365's records.
> It never writes changes back to Entra ID.
> Your write-through operations are the only actions that modify your tenant's configuration.

## Sync Types

Two distinct sync jobs run independently:

- **Partner Sync** — Updates partner organization details, [cross-tenant access policies](/docs/glossary/glossary), trust scores, conditional access policy coverage, sensitivity labels, and SharePoint site associations.
  This ensures the partner list and policy configurations shown in Partner365 match what is actually configured in Entra ID.
- **Guest Sync** — Updates [guest](/docs/glossary/glossary) user profiles, sign-in activity timestamps, and invitation status.
  This keeps the guest list current and ensures activity metrics (used by [compliance reports](/docs/reports/compliance-reports) and access reviews) reflect the latest data from Entra ID.

Each sync type runs independently and can be configured with its own interval.

## Configuring Intervals

The sync page lets you adjust the interval for each sync type.
The default interval is 15 minutes, which works well for most organizations.
However, you may want to adjust it depending on your situation:

- **Increase the interval** (e.g., 30 or 60 minutes) for larger tenants with many partners and guests.
  This reduces the volume of Graph API calls and helps avoid throttling.
  If changes outside Partner365 are rare, a longer interval has minimal impact on data freshness.
- **Decrease the interval** (e.g., 5 or 10 minutes) if your organization frequently makes changes outside Partner365 and you need faster reconciliation.
  Be aware this increases API call volume and may contribute to throttling on large tenants.

Changes to the sync interval take effect on the next scheduled run.

## Sync Status and History

The sync page displays the current status for each sync type:

- **Last sync time** — When the most recent sync completed.
- **Status** — Whether the last run succeeded or failed. Failed syncs display an error summary.
- **Records updated** — How many records were created, updated, or removed during the last run.
- **Duration** — How long the sync took to complete.
  Increasing duration over time may indicate a growing tenant or network issues.

Below the current status, a history table shows recent sync runs for each type.
This is useful for spotting patterns — recurring failures, steadily increasing durations, or unexpected spikes in record counts that might indicate bulk changes in Entra ID.

## Manual Sync

Click **Sync Now** next to either sync type to trigger an immediate run outside the regular schedule.
Manual sync is useful in several situations:

- **After changes in the Entra admin center** — If you or a colleague modified policies or guest accounts directly in Entra ID, a manual sync brings those changes into Partner365 right away rather than waiting for the next scheduled run.
- **Before creating access reviews** — Run a guest sync first to ensure you are working with the freshest sign-in activity data.
  This makes access review decisions more accurate.
- **After resolving connection issues** — If sync was failing due to an expired client secret or network problem, trigger a manual run after fixing the issue to confirm sync is working and to catch up on any missed data.
- **Before generating compliance reports** — Ensure the data underlying the report is as current as possible.

A manual sync runs the same process as the scheduled sync — there is no difference in behavior or scope.
