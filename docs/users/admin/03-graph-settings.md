---
title: Graph API Settings
admin: true
---

# Graph API Settings

The Admin > Graph page shows the Microsoft Graph API connection status.

## Connection Status

View the current configuration:
- **Tenant ID** — Your Microsoft 365 tenant identifier
- **Client ID** — The app registration client ID
- **Connection Status** — Whether Partner365 can successfully authenticate and call Graph API
- **Token Status** — Current access token validity

## Permissions

The page displays the Graph API permissions granted to the application. Partner365 requires specific permissions to manage cross-tenant access policies, guest users, and read directory data.

## Troubleshooting

If the connection status shows an error:
1. Verify your `.env` file has the correct `MICROSOFT_GRAPH_TENANT_ID`, `MICROSOFT_GRAPH_CLIENT_ID`, and `MICROSOFT_GRAPH_CLIENT_SECRET`
2. Check that the app registration in Azure has the required API permissions
3. Ensure admin consent has been granted for all permissions
4. Review the Activity Log for detailed error messages
