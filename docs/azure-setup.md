# Azure Setup Guide

This guide walks through creating the Azure AD app registration required for Partner365 to communicate with the Microsoft Graph API.

## Step 1: Create App Registration

1. Go to [Azure Portal](https://portal.azure.com) → **Microsoft Entra ID** → **App registrations**
2. Click **New registration**
3. Name: `Partner365` (or your preferred name)
4. Supported account types: **Accounts in this organizational directory only**
5. Redirect URI: Leave blank (not needed for client credentials flow)
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
| `Policy.Read.All` | Read cross-tenant access policies |
| `Policy.ReadWrite.CrossTenantAccess` | Create/update/delete partner policies |
| `User.Invite.All` | Send B2B guest invitations |
| `User.Read.All` | List and read guest user profiles |
| `User.ReadWrite.All` | Update and delete guest users |
| `Directory.Read.All` | Resolve tenant information |

3. Click **Grant admin consent for [your organization]**
4. Verify all permissions show a green checkmark under "Status"

> **Note:** These are **Application** permissions (not Delegated). Partner365 uses the client credentials OAuth2 flow, which requires application-level consent from a Global Administrator.

## Step 5: Configure Environment

Add the credentials to your `.env` file:

```env
MICROSOFT_GRAPH_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MICROSOFT_GRAPH_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MICROSOFT_GRAPH_CLIENT_SECRET=your-secret-value-here
```

Optional overrides (defaults are usually fine):

```env
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

## National Cloud Endpoints

If your tenant is in a national cloud, override the base URL:

| Cloud | Base URL |
|-------|---------|
| Global | `https://graph.microsoft.com/v1.0` (default) |
| US Government L4 | `https://graph.microsoft.us/v1.0` |
| US Government L5 (DOD) | `https://dod-graph.microsoft.us/v1.0` |
| China (21Vianet) | `https://microsoftgraph.chinacloudapi.cn/v1.0` |

Also update the token endpoint in `MicrosoftGraphService` if needed (currently hardcoded to `login.microsoftonline.com`).
