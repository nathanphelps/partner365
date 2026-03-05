---
title: Graph API Settings
admin: true
---

# Graph API Settings

The Admin > Graph page is where administrators view and manage the Microsoft [Graph API](/docs/glossary/01-glossary) connection that Partner365 relies on for all interactions with your Entra ID tenant.
Every operation — reading partner data, inviting guests, modifying cross-tenant policies — flows through this connection.

## Connection Configuration

The Graph page displays the current connection parameters.
These correspond to the app registration you created in the Azure portal when setting up Partner365:

- **Cloud Environment** — The Microsoft cloud your tenant belongs to: Commercial (most organizations), GCC, or GCC High.
  This determines which API endpoints Partner365 uses.
  Selecting the wrong environment means API calls are directed to the wrong cloud and will fail.
- **Tenant ID** — Your Microsoft 365 tenant identifier (a GUID).
  This tells Partner365 which tenant to authenticate against and manage.
- **Client ID** — The application (client) ID from your app registration.
  This identifies Partner365 to the Microsoft identity platform.
- **Client Secret** — The authentication credential for the app registration.
  Displayed as masked for security.
  Client secrets have expiration dates set in Azure — when they expire, Partner365 loses the ability to authenticate.
- **API Scopes** — The permission scopes requested during authentication.
- **Base URL** — The Graph API endpoint URL, determined by the cloud environment selection.

Changes to these settings take effect immediately.
Always use the Test Connection button after making changes to verify the configuration is correct.

## Testing the Connection

The **Test Connection** button makes an authenticated call to the Graph API without modifying any data in your tenant.
It verifies that:

- The Tenant ID, Client ID, and Client Secret are valid and can obtain an access token.
- The token grants access to the expected Graph API endpoints.
- Network connectivity between Partner365 and the Microsoft identity platform is working.

Use this after any configuration change — updating the client secret, changing the cloud environment, or modifying the tenant ID.
A successful test confirms Partner365 can communicate with your tenant.
A failed test will display an error message indicating what went wrong.

## Admin Consent

Some Graph API permissions require tenant administrator consent before they take effect.
The **Grant Admin Consent** button initiates the consent flow, which redirects to the Microsoft Entra admin center where a tenant admin can review and approve the requested permissions.

You need to grant admin consent in the following situations:

- **Initial setup** — When Partner365 is first configured, all required permissions need admin consent.
- **After adding new permissions** — If the app registration in Azure is updated with additional API permissions (for example, to support a new Partner365 feature), those permissions need fresh admin consent.
- **After consent was revoked** — If an Entra ID admin revokes consent for the app, it must be re-granted here.

Admin consent is a one-time action per permission set.
Once granted, it remains in effect until explicitly revoked in the Entra admin center.

## Permissions

The permissions section lists the Graph API permissions currently granted to Partner365.
Each permission enables specific functionality:

- **`Policy.ReadWrite.CrossTenantAccess`** — Create, read, update, and delete [cross-tenant access policies](/docs/glossary/01-glossary).
  Required for all partner policy management.
- **`User.Invite.All`** — Send [guest](/docs/glossary/01-glossary) invitations to external users.
  Required for the guest invitation workflow.
- **`User.ReadWrite.All`** — Read and update user profiles, including guest accounts.
  Required for guest lifecycle management and profile synchronization.
- **`Directory.Read.All`** — Read directory data including organization details, domains, and tenant information.
  Required for partner organization lookups and tenant resolution.
- **`Policy.Read.ConditionalAccess`** — Read conditional access policies.
  Required for assessing CA policy coverage in compliance reports and partner trust scores.

If a permission is missing, the specific feature that depends on it will fail.
The permissions list helps you identify gaps — if guest invitations are failing, check whether `User.Invite.All` is present.
If policy changes fail, verify `Policy.ReadWrite.CrossTenantAccess` is granted.

## Troubleshooting

Common connection issues and their resolutions:

- **Connection failed / authentication error** — Verify the Tenant ID and Client ID are correct (copy them directly from the app registration in Azure to avoid transcription errors).
  Confirm the Client Secret is current and has not expired.
  Check the Azure portal for the secret's expiration date.
- **Insufficient permissions / access denied** — The app registration is missing one or more required API permissions, or admin consent has not been granted.
  Go to the app registration in Azure, add the missing permissions under API Permissions, then return to this page and click Grant Admin Consent.
- **Client secret expired** — Client secrets have a finite lifetime (typically 1-2 years).
  When a secret expires, all API calls fail.
  Generate a new secret in the Azure portal under Certificates & Secrets, then update the Client Secret field on this page and test the connection.
- **GCC / GCC High connection issues** — If you are in a government cloud, ensure the Cloud Environment dropdown is set correctly.
  Commercial, GCC, and GCC High use different API endpoints and identity platforms.
  A mismatch means Partner365 is trying to authenticate against the wrong cloud entirely.
- **Intermittent failures** — If the connection works sometimes but not others, check Azure service health for Graph API outages.
  Also verify that your network allows outbound HTTPS traffic to `login.microsoftonline.com` and `graph.microsoft.com` (or their government cloud equivalents).

> **Good to know:** Set a calendar reminder for 30 days before your client secret expires.
> An expired secret causes all Partner365 operations to fail silently until it is regenerated and updated.
> The expiration date is visible in the Azure portal under your app registration's Certificates & Secrets section.
