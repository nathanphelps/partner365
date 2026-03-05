# Azure Setup Guide

This guide walks through creating the Azure AD app registration required for Partner365 to communicate with the Microsoft Graph API.

## Step 1: Create App Registration

1. Go to [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** → **App registrations**
2. Click **New registration**
3. Name: `Partner365` (or your preferred name)
4. Supported account types: **Accounts in this organizational directory only**
5. Redirect URIs: **Web** → add both:
   - `https://your-app-url/admin/graph/consent/callback` (admin consent flow)
   - `https://your-app-url/auth/sso/callback` (SSO sign-in)
6. Click **Register**

## Step 2: Note the IDs

From the app registration overview page, copy:

- **Application (client) ID** → this is your `MICROSOFT_GRAPH_CLIENT_ID`
- **Directory (tenant) ID** → this is your `MICROSOFT_GRAPH_TENANT_ID`

## Step 3: Create Client Secret

1. Go to **Certificates & secrets** → **Client secrets**
2. Click **New client secret**
3. Description: `Partner365 Production` (or similar)
4. Expiry: Choose based on your rotation policy (recommended: 12 months)
5. Click **Add**
6. **Copy the secret value immediately** — it won't be shown again
7. This is your `MICROSOFT_GRAPH_CLIENT_SECRET`

## Step 4: Configure API Permissions

1. Go to **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**
2. Add the following permissions:

| Permission | Purpose |
|-----------|---------|
| `User.Read.All` | List and read guest user profiles |
| `User.ReadWrite.All` | Update and delete guest users |
| `User.Invite.All` | Send B2B guest invitations |
| `Policy.Read.All` | Read cross-tenant access policies and Conditional Access policies |
| `Policy.ReadWrite.CrossTenantAccess` | Create/update/delete partner policies |
| `Policy.ReadWrite.Authorization` | Manage external collaboration settings (invitation controls, domain lists) |
| `CrossTenantInformation.ReadBasic.All` | Resolve tenant information during partner onboarding |
| `AccessReview.ReadWrite.All` | Create and manage access review definitions |
| `EntitlementManagement.ReadWrite.All` | Create/manage access packages, catalogs, assignments, and connected organizations |
| `Group.Read.All` | List groups for entitlement management resource selection |
| `GroupMember.Read.All` | Read guest user group memberships (access visibility) |
| `AppRoleAssignment.ReadWrite.All` | Read guest user app role assignments (access visibility) |
| `Team.ReadBasic.All` | Read guest user Teams memberships (access visibility) |
| `Sites.Read.All` | Read SharePoint sites for guest access visibility and entitlement resources |
| `Sites.FullControl.All` | Read SharePoint site permissions for guest user access tracking |
| `InformationProtection.Read.All` | Read sensitivity labels, label policies, and site label assignments |

3. Click **Grant admin consent for [your organization]**
4. Verify all permissions show a green checkmark under "Status"

> **Note:** These are **Application** permissions (not Delegated). Partner365 uses the client credentials OAuth2 flow, which requires application-level consent from a Global Administrator.

### Delegated Permissions (for SSO)

If you plan to enable Entra ID SSO, also add these **Delegated** permissions under **Microsoft Graph**:

| Permission | Purpose |
|-----------|---------|
| `openid` | OpenID Connect sign-in |
| `profile` | Read user's basic profile |
| `email` | Read user's email address |
| `User.Read` | Sign in and read user profile |

These do not require admin consent — users consent on first SSO login.

## Step 5: Configure Environment

Add the credentials to your `.env` file:

```env
MICROSOFT_GRAPH_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MICROSOFT_GRAPH_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MICROSOFT_GRAPH_CLIENT_SECRET=your-secret-value-here
```

Optional overrides (defaults are usually fine):

```env
MICROSOFT_GRAPH_CLOUD_ENVIRONMENT=commercial   # or gcc_high
MICROSOFT_GRAPH_SCOPES="https://graph.microsoft.com/.default"
MICROSOFT_GRAPH_BASE_URL="https://graph.microsoft.com/v1.0"
MICROSOFT_GRAPH_SYNC_INTERVAL=15
```

## Step 6: Verify Connection

Run the sync command to verify the Graph API connection works:

```bash
php artisan sync:partners
```

If successful, you'll see:
```
Fetching partner configurations from Graph API...
Synced X partner organizations.
```

If it fails, check:
- Are the credentials correct in `.env`?
- Has admin consent been granted for all permissions?
- Is the app registration in the correct tenant?

## Security Recommendations

- **Rotate secrets** on a regular schedule (at least annually)
- **Use Azure Key Vault** in production to store the client secret rather than `.env`
- **Monitor sign-in logs** for the app registration in Entra ID
- **Use Conditional Access** to restrict where the app can authenticate from
- **Principle of least privilege** — only grant the permissions listed above; do not grant broader admin roles

## GCC High / National Cloud Support

Partner365 supports GCC High out of the box via the **Cloud Environment** setting on the Admin → Microsoft Graph page. Select "GCC High" and the app automatically uses the correct endpoints:

| Setting | Commercial | GCC High |
|---------|-----------|----------|
| Login URL | `login.microsoftonline.com` | `login.microsoftonline.us` |
| Graph Base URL | `graph.microsoft.com/v1.0` | `graph.microsoft.us/v1.0` |
| Default Scopes | `graph.microsoft.com/.default` | `graph.microsoft.us/.default` |

You can also set the default via environment variable:

```env
MICROSOFT_GRAPH_CLOUD_ENVIRONMENT=gcc_high
```

The scopes and base URL fields remain editable for manual overrides if needed.

## Admin Consent

Partner365 includes an admin consent button on the Graph settings page. Instead of navigating to the Azure Portal, admins can click **Grant Admin Consent** to open a Microsoft popup directly from the app.

The consent flow uses a redirect URI at `/admin/graph/consent/callback`. Make sure this URL is registered as a **Web** redirect URI in your app registration (see Step 1).

## Entra ID SSO

Partner365 supports optional Entra ID SSO alongside password authentication. SSO reuses the same app registration — no separate registration needed.

### Setup

1. Ensure the redirect URI `https://your-app-url/auth/sso/callback` is registered (see Step 1)
2. Ensure delegated permissions (`openid`, `profile`, `email`, `User.Read`) are added (see Step 4)
3. In Partner365, go to **Admin → SSO** and enable SSO

### Configuration Options

| Setting | Description |
|---------|-------------|
| **Enable SSO** | Master toggle for the "Sign in with Microsoft" button on the login page |
| **Auto-approve** | When on, SSO users are immediately approved. When off, they enter the approval queue. |
| **Default Role** | Role assigned to new SSO users (admin, operator, or viewer) |
| **Group Mapping** | Map Entra security group IDs to roles. Highest-privilege match wins. |
| **Restrict to Mapped Groups** | When on, only users in mapped groups can sign in via SSO. |

Group mapping is evaluated at first login only. Admins can override roles manually afterward.
