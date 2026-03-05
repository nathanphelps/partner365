---
title: Troubleshooting
admin: true
---

# Troubleshooting

This page covers common issues administrators encounter in Partner365 and how to resolve them.
For each issue, the diagnostic steps are listed in order of likelihood — start at the top and work down.

## Sync Failures

When background sync fails, check the sync history table on the [Sync page](/docs/admin/sync-configuration) for error messages.
Common causes:

- **Graph API connection lost** — The most frequent cause.
  Go to Admin > [Graph](/docs/admin/graph-settings) and click Test Connection.
  If the test fails, the sync cannot reach the Graph API.
  Check the connection configuration and resolve any authentication errors before retrying.
- **Client secret expired** — Client secrets have a finite lifetime set in Azure.
  When they expire, all API calls fail, including sync.
  Generate a new secret in the Azure portal under your app registration's Certificates & Secrets section, then update it on the Graph page and test the connection.
- **API rate limiting / throttling** — Microsoft throttles Graph API calls when request volume is too high.
  If sync history shows throttling errors (HTTP 429), increase the sync interval on the Sync page to reduce call frequency.
  For large tenants with thousands of guests or partners, a 30 or 60 minute interval may be necessary.
- **Transient network issues** — Occasional failures followed by successful runs usually indicate network instability.
  These typically self-resolve.
  If failures persist, check network connectivity between Partner365 and `graph.microsoft.com`.

The [activity log](/docs/activity/activity-log) may contain additional error details for failed sync operations.
Filter by Action Type "Sync" to find relevant entries.

## Graph API Permission Issues

If specific operations fail with "Insufficient privileges" or "Authorization_RequestDenied" errors:

1. Go to Admin > [Graph](/docs/admin/graph-settings) and review the Permissions section.
2. Verify all required permissions are listed: `Policy.ReadWrite.CrossTenantAccess`, `User.Invite.All`, `User.ReadWrite.All`, `Directory.Read.All`, `Policy.Read.ConditionalAccess`.
3. If any are missing, add them to the app registration in the Azure portal under API Permissions.
4. Return to the Graph page and click **Grant Admin Consent**.
   Permissions are not effective until admin consent is granted, even if they are listed in the app registration.
5. After granting consent, click **Test Connection** to verify.

Specific permission gaps cause specific feature failures.
For example, if [guest](/docs/glossary/glossary) invitations fail but everything else works, `User.Invite.All` is likely missing.
If [cross-tenant access policy](/docs/glossary/glossary) changes fail, check `Policy.ReadWrite.CrossTenantAccess`.

## Users Cannot Access the App

Walk through this diagnostic flow:

1. **Is the user registered?** Check the [Users page](/docs/admin/user-management).
   If they do not appear in the list, they have not registered or signed in via SSO yet.
   Ask them to visit the login page.
2. **Is the user approved?** Users with Pending status see a "Pending Approval" page and cannot access any application functionality.
   Click Approve to grant them access.
3. **Does the user have the right role?** A user with the Viewer role cannot perform write operations.
   If they are trying to add partners or invite guests and getting errors, they need the Operator or Admin role.
4. **For SSO users: is SSO enabled and configured correctly?**
   Check the SSO Settings page.
   If SSO is enabled but auto-approve is disabled, new SSO users land in the pending queue.
   If SSO itself is misconfigured, users will see authentication errors at login — see the SSO Login Failures section below.

> **Good to know:** The most common "I cannot access the app" issue is simply that the user has not been approved yet.
> If you use SSO with auto-approve disabled, this is expected behavior for every new user.

## Data Out of Sync with Entra Admin Center

If the data shown in Partner365 does not match what you see in the Microsoft Entra admin center:

1. Go to the [Sync page](/docs/admin/sync-configuration) and click **Sync Now** for the relevant sync type (Partner Sync or Guest Sync).
2. Wait for the sync to complete and check whether the data is now current.
3. If sync succeeds but the data is still incorrect, verify that the app registration has read permissions for the relevant data type.
   For example, conditional access data requires `Policy.Read.ConditionalAccess`, and [collaboration settings](/docs/admin/collaboration-settings) require `Policy.Read.All`.
4. Some data types in Entra ID have propagation delays.
   If a change was made very recently (within the last few minutes), wait a few minutes and sync again.

## High API Error Rates

If you observe frequent errors in the sync history or activity log:

- **Check Azure service health** — Microsoft publishes service health status for Graph API.
  Outages or degradation on their end will cause Partner365 operations to fail regardless of your configuration.
- **Verify client secret validity** — An expired secret causes every API call to fail.
  This is the single most common root cause of sudden, widespread errors.
- **Check for API throttling** — HTTP 429 responses indicate throttling.
  Increase the sync interval and consider whether any bulk operations (large access reviews, mass guest invitations) are contributing to high call volume.
- **Review error details** — Expand error entries in the activity log or sync history for specific error codes and messages.
  Graph API errors are usually descriptive enough to point you toward the cause.

## SSO Login Failures

If users cannot sign in via [SSO](/docs/glossary/glossary):

- **Redirect URI mismatch** — The redirect URI configured in the Entra app registration must match exactly what Partner365 sends during the OAuth flow, including the scheme (`https://`), domain, port (if non-standard), and path.
  Even a trailing slash difference will cause the login to fail.
  Check the app registration under Authentication > Redirect URIs.
- **Missing delegated permissions** — SSO requires delegated permissions: `openid`, `profile`, `email`, and `User.Read`.
  These must be added to the app registration and granted admin consent.
  Without them, the token exchange fails.
- **Wrong cloud environment** — If your tenant is in GCC or GCC High, the Graph page's Cloud Environment setting must match.
  SSO authenticates against the identity platform for that cloud, and a mismatch means authentication requests go to the wrong endpoint entirely.
- **Browser-specific issues** — Ask the user to check the browser console (F12 > Console tab) for specific OAuth error codes.
  Common codes include `AADSTS50011` (redirect URI mismatch), `AADSTS65001` (consent required), and `AADSTS700016` (application not found in the tenant).

If SSO was working previously and suddenly stopped, the most likely causes are an expired client secret or a change to the app registration's redirect URIs.
