---
title: Troubleshooting
---

# Troubleshooting

This page covers common issues you may encounter when working with partners in Partner365, along with their causes and solutions.

## "Tenant Not Found" When Resolving

When you enter a domain or [tenant](/docs/glossary/glossary) ID during partner creation and the resolve step fails, the most common causes are:

- **Not a valid M365 tenant** — The domain may not be associated with a Microsoft 365 business or enterprise tenant. Consumer domains such as outlook.com, gmail.com, hotmail.com, and live.com are not supported.
- **Typo in the domain or tenant ID** — Double-check the domain spelling. If using a tenant ID, verify it is a valid GUID format.
- **Tenant exists but is not discoverable** — Some organizations configure their tenant to not appear in directory lookups. In this case, try using the tenant ID directly instead of the domain. Ask the partner for their tenant ID if needed.

> **Good to know:** You can verify a domain is associated with an M365 tenant by checking for DNS records like `_sipfederationtls._tcp.<domain>` or asking the partner to confirm their tenant ID from their Entra ID portal.

## Partner Added but Policies Not Applying

If you have added a partner and configured policies, but the policies do not seem to take effect for the partner's users:

1. **Check Graph API connection** — Go to Admin > Graph Settings and verify the connection is active. A disconnected or errored API integration means policy changes are not reaching Entra ID.
2. **Verify app permissions** — The app registration used by Partner365 must have the `Policy.ReadWrite.CrossTenantAccess` permission. An admin can verify this in the Azure portal under App Registrations > API Permissions.
3. **Check the activity log** — Navigate to the [activity log](/docs/activity/activity-log) and look for recent entries related to this partner. Error entries will indicate what went wrong during the policy write.
4. **Allow propagation time** — Entra ID policy changes can take a few minutes to propagate across Microsoft services. Wait 5-10 minutes and test again.

## Trust Score Seems Wrong

The [trust score](/docs/concepts/trust-score) is recalculated during each sync cycle (every 15 minutes). If you have just changed a policy and the score has not updated:

- **Wait for the next sync** — The score updates on the next background sync, not immediately after a policy change.
- **Trigger a manual sync** — An admin can trigger a manual sync from Admin > Sync to force an immediate recalculation.
- **Review the breakdown** — Open the partner's [detail page](/docs/partners/partner-details) and check the trust score breakdown table. Each factor shows pass or fail status, which clarifies exactly what is contributing to the score.

> **Good to know:** A score drop after adding guest users is normal. The guest activity and stale guest ratio factors adjust as new guests are added and before they have had time to sign in.

## Sync Not Updating Partner Data

If partner data appears stale and the Last Sync timestamp on the [partner list](/docs/partners/viewing-partners) is not advancing:

- **Check Admin > Sync** — Look for error messages on the sync status page. The most common cause is an expired client secret for the Graph API app registration.
- **Renew the client secret** — If the secret has expired, generate a new one in the Azure portal and update it in Partner365 under Admin > [Graph API Settings](/docs/admin/graph-settings).
- **Check network connectivity** — The Partner365 server must be able to reach `graph.microsoft.com` and `login.microsoftonline.com`. Firewall or proxy rules may block these endpoints.
- **Review queue health** — Sync jobs run through the Laravel queue. If the queue worker is down, syncs will not execute. An admin can check queue status in the application logs.

## Cannot Delete a Partner

If you are unable to delete a partner:

- **Check your role** — Deleting a partner requires the **Operator** or **Admin** role. Viewers cannot perform this action. Ask an admin to check your role assignment if you believe it is incorrect.
- **Graph API failure** — If the delete button triggers an error, the Graph API call to remove the cross-tenant access policy may have failed. Check the [activity log](/docs/activity/activity-log) for error details. Common causes include expired credentials or insufficient API permissions.
- **Partner already deleted in Entra ID** — If someone removed the cross-tenant policy directly in the Entra ID portal, Partner365 may encounter a conflict when trying to delete it. In this case, contact an admin to resolve the inconsistency — a manual sync should reconcile the state.
