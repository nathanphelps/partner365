# Entra ID SSO Design

Date: 2026-03-05

## Overview

Add Entra ID SSO (OpenID Connect) to Partner365 as a login option alongside existing Fortify email/password authentication. SSO configuration is managed through a new admin settings page. Uses the same Entra app registration as the Graph API integration.

## Decisions

- **Dual mode**: Both password login and SSO always available side by side
- **Single app registration**: SSO reuses the existing Graph API Entra app registration (same tenant ID, client ID, client secret) with an additional redirect URI and delegated permissions
- **Implementation**: Laravel Socialite + `socialiteproviders/microsoft` with dynamic runtime config from `settings` table
- **GCC High**: Cloud environment toggle (already in Graph settings) drives OIDC authority URLs automatically (login.microsoftonline.com vs login.microsoftonline.us)
- **No advanced OIDC config**: Cloud toggle is sufficient; no custom endpoint overrides, session lifetime overrides, domain restrictions, or SAML fallback

## Admin SSO Settings Page

New tab at `/admin/sso` alongside Graph, Users, Collaboration, Sync, Syslog.

### Settings (stored in `settings` table, group: `sso`)

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | boolean | false | Master toggle for SSO |
| `auto_approve` | boolean | false | SSO users skip approval queue |
| `default_role` | enum | viewer | Role assigned at first SSO login |
| `group_mapping_enabled` | boolean | false | Enable Entra group-to-role mapping |
| `group_mappings` | JSON | [] | Array of `{ entra_group_id, entra_group_name, role }` |
| `restrict_provisioning_to_mapped_groups` | boolean | false | Only provision users in mapped groups |

Credentials are not duplicated. The SSO page shows a read-only indicator of whether Graph credentials are configured (with link to Graph page if not).

## Authentication Flow

### Routes

- `GET /auth/sso` — Redirect to Entra OIDC authorize endpoint via Socialite
- `GET /auth/sso/callback` — Handle callback, create/update user, log in

### Login Page Changes

When SSO is enabled (Inertia shared prop), show "Sign in with Microsoft" button above the email/password form. Both options always visible.

### SSO Callback Logic (SsoController)

1. Socialite exchanges authorization code for tokens, returns Entra user profile
2. Look up user by `entra_id` column, fall back to email match
3. **Existing user**: Update name if changed, log them in
4. **New user + provisioning allowed**: Create user with:
   - Default role (or mapped role if group mapping enabled)
   - `approved_at` set if auto-approve on, null otherwise
   - `entra_id` set to Entra object ID
5. **New user + provisioning blocked** (not in mapped groups when restrict is on): Redirect to login with error
6. Log authentication event via `ActivityLogService`

### Socialite Provider Config

Built dynamically at runtime from `Setting::get('graph', ...)` credentials:

- **Commercial**: `https://login.microsoftonline.com/{tenant}/v2.0`
- **GCC High**: `https://login.microsoftonline.us/{tenant}/v2.0`

## User Model Changes

Add nullable `entra_id` column (string) — stores the Entra object ID. Used for matching on subsequent SSO logins (more reliable than email).

## Group Mapping & Role Assignment

At SSO login time (first login only):

1. When group mapping enabled, query user's Entra group memberships via `MicrosoftGraphService` (client credentials)
2. Compare group IDs against configured `group_mappings`
3. Match found: assign highest-privilege matching role (admin > operator > viewer)
4. No match + restrict on: deny provisioning
5. No match + restrict off: fall back to `default_role`

Group mapping only applies at provisioning (first login). Admin can override roles manually afterward; subsequent SSO logins do not re-evaluate.

### Admin UI for Group Mappings

Repeatable row component on SSO settings page: text input for Entra Group ID, text input for display name, role dropdown. Add/remove rows.

## App Registration Script Changes

Update `scripts/setup-app-registration.sh`:

1. Add SSO callback redirect URI: `${APP_URL}/auth/sso/callback` (alongside existing consent callback)
2. Add delegated permissions: `openid`, `profile`, `email` for OIDC user sign-in
3. Update completion message to mention SSO configuration in Admin settings

## Testing Strategy

- Mock Socialite's `driver('microsoft')` to return fake Entra user profiles
- Test SSO callback: new user provisioning (auto-approve on/off), existing user login, blocked provisioning
- Test group mapping role assignment (match, no match, restrict mode)
- Test SSO disabled state — SSO routes redirect to login with error
- Test login page renders SSO button only when enabled (Inertia prop)
- Use existing `Http::fake()` pattern for Graph API group membership mocks
