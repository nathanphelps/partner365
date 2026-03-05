---
title: Sync Configuration
admin: true
---

# Sync Configuration

The Admin > Sync page shows the status of background data synchronization.

## How Sync Works

Partner365 runs background sync jobs every 15 minutes to reconcile local data with Microsoft Entra ID:
- **Partner Sync** — Updates partner organization details and cross-tenant access policies
- **Guest Sync** — Updates guest user profiles, sign-in activity, and invitation status

## Sync Status

The page shows:
- Last sync time for each sync type
- Success/failure status
- Number of records updated

## Manual Sync

Click **Sync Now** to trigger an immediate sync. This is useful after making changes in the Microsoft Entra admin center that you want reflected immediately in Partner365.
