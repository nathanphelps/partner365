# SSO Settings

The SSO settings page lets administrators enable Entra ID (Azure AD) single sign-on for the application.

## Prerequisites

SSO uses the same Entra app registration as the Graph API connection. Before enabling SSO:

1. Ensure Graph API credentials are configured on the **Microsoft Graph** page
2. Add the SSO redirect URI (`https://your-app-url/auth/sso/callback`) to your app registration
3. Add delegated permissions (`openid`, `profile`, `email`, `User.Read`) to your app registration

See the [Azure Setup Guide](/docs/azure-setup#entra-id-sso) for detailed instructions.

## Settings

### Enable Entra ID SSO

Master toggle. When enabled, a "Sign in with Microsoft" button appears on the login page alongside the standard email/password form. Both login methods are always available.

### Auto-approve SSO users

When enabled, users who sign in via SSO for the first time are automatically approved and can access the application immediately. When disabled, new SSO users enter the approval queue and must be approved by an admin on the **User Management** page before they can access the application.

### Default Role

The role assigned to new users who sign in via SSO. Choose from Admin, Operator, or Viewer. This applies when no group mapping matches (or when group mapping is disabled).

## Group Mapping

Group mapping lets you assign roles automatically based on Entra ID security group membership.

### Enable group-to-role mapping

When enabled, the application checks the user's Entra group memberships at first sign-in and assigns a role based on configured mappings.

### Only allow users in mapped groups

When enabled, users who are not members of any mapped group are denied access entirely. When disabled, unmatched users fall back to the default role.

### Configuring mappings

Each mapping consists of:

- **Group ID** - The Entra security group's object ID (UUID)
- **Display Name** - A human-readable label for reference (not used for matching)
- **Role** - The Partner365 role to assign (Admin, Operator, or Viewer)

If a user belongs to multiple mapped groups, the highest-privilege role wins (Admin > Operator > Viewer).

Group mapping is evaluated at first login only. After provisioning, admins can change a user's role manually on the User Management page without it being overwritten on subsequent SSO logins.
