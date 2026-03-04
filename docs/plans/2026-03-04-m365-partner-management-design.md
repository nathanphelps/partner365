# M365 External Partner Management App — Design Document

**Date:** 2026-03-04
**Status:** Approved

## Problem Statement

Managing 100+ external partner organizations in Microsoft Entra ID is painful through the admin center. There is no consolidated dashboard, onboarding is manual and error-prone, and guest user lifecycle tracking is insufficient. This app provides a purpose-built interface for IT admins, business owners, and helpdesk/ops staff to manage B2B collaboration, cross-tenant access policies, MFA trust, and guest users.

## Architecture

**Stack:** Laravel 12 + Vue 3 + Inertia.js + Tailwind CSS + shadcn-vue

**Approach:** Monolithic — all-in-one Laravel app with server-side Graph API calls. Inertia provides SPA-like experience without a separate API layer.

### Authentication

- **Production:** Entra ID SSO via OAuth2/OIDC (Laravel Socialite with Microsoft provider). On login, acquire and store Graph API access/refresh tokens.
- **Dev/Test:** Existing Fortify local auth. Graph API calls use app-only client credentials flow (client ID + secret stored in `.env`).

### Data Model: Hybrid

- Local database (SQLite for dev, PostgreSQL for prod) caches partner configurations, guest user metadata, and app-specific data (categories, notes, owners).
- Background sync job polls Graph API every 15 minutes to keep local data fresh.
- Write operations go to Graph API first, then update local cache on success.
- Graph API is always the source of truth.

### RBAC: 3-Tier

| Role | Capabilities |
|------|-------------|
| Admin | Full control: manage all partners, policies, templates, invite guests, configure app settings |
| Operator | Manage partners/guests within pre-set policies, use templates, invite guests |
| Viewer | Read-only dashboards, reports, and activity log |

## Graph API Integration

### Required Permissions (Application)

| Permission | Purpose |
|---|---|
| `Policy.Read.All` | Read cross-tenant access policies |
| `Policy.ReadWrite.CrossTenantAccess` | Create/update/delete partner policies, MFA trust |
| `User.Invite.All` | Send B2B invitations |
| `User.Read.All` | List/read guest users |
| `User.ReadWrite.All` | Update/delete guest users, reset redemption |
| `Directory.Read.All` | Read org info for partner tenant lookup |

### Key API Endpoints

| Feature | Endpoint | Method |
|---|---|---|
| Default cross-tenant policy | `/policies/crossTenantAccessPolicy/default` | GET, PATCH |
| List partner configs | `/policies/crossTenantAccessPolicy/partners` | GET |
| Create partner config | `/policies/crossTenantAccessPolicy/partners` | POST |
| Get/update/delete partner | `/policies/crossTenantAccessPolicy/partners/{tenantId}` | GET, PATCH, DELETE |
| Invite guest user | `/invitations` | POST |
| List guest users | `/users?$filter=userType eq 'Guest'` | GET |
| Get/update/delete user | `/users/{id}` | GET, PATCH, DELETE |
| Resolve tenant info | `/tenantRelationships/findTenantInformationByTenantId(tenantId='{id}')` | GET |

### Service Classes

```
App\Services\
  MicrosoftGraphService.php      — Token management, HTTP client wrapper
  CrossTenantPolicyService.php   — Partner CRUD, MFA trust, inbound/outbound settings
  GuestUserService.php           — Invitations, user listing, lifecycle management
  TenantResolverService.php      — Resolve tenant ID to display name/domain
```

### Background Sync

Laravel scheduled commands via the queue:
- `sync:partners` — Fetches all partner configs, upserts `partner_organizations`
- `sync:guests` — Fetches all guest users, upserts `guest_users`
- Runs every 15 minutes (configurable)
- Logs sync results to activity_log

## Database Schema

### `partner_organizations`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id | string unique | From Entra |
| display_name | string | Resolved via Graph |
| domain | string | Primary domain |
| category | enum | vendor, contractor, strategic_partner, customer, other |
| owner_user_id | FK → users | Internal person responsible |
| notes | text | Free-text documentation |
| b2b_inbound_enabled | boolean | Cached from Graph |
| b2b_outbound_enabled | boolean | Cached from Graph |
| mfa_trust_enabled | boolean | Cached from Graph |
| device_trust_enabled | boolean | Cached from Graph |
| direct_connect_enabled | boolean | Cached from Graph |
| raw_policy_json | json | Full Graph API response |
| last_synced_at | timestamp | |
| created_at, updated_at | timestamps | |

### `guest_users`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| entra_user_id | string unique | From Entra |
| email | string | |
| display_name | string | |
| user_principal_name | string | |
| partner_organization_id | FK → partner_organizations | nullable |
| invitation_status | enum | pending_acceptance, accepted, failed |
| invited_by_user_id | FK → users | nullable |
| invited_at | timestamp | |
| last_sign_in_at | timestamp | From Graph signInActivity |
| account_enabled | boolean | |
| last_synced_at | timestamp | |
| created_at, updated_at | timestamps | |

### `partner_templates`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | string | e.g., "Standard Vendor" |
| description | text | |
| policy_config | json | Default B2B settings, MFA trust, allowed apps/groups |
| created_by_user_id | FK → users | |
| created_at, updated_at | timestamps | |

### `activity_log`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users | Who performed the action |
| action | enum | partner_created, partner_updated, partner_deleted, guest_invited, guest_removed, policy_changed, template_created, sync_completed |
| subject_type | string | Polymorphic |
| subject_id | bigint | Polymorphic |
| details | json | Before/after diff or context |
| created_at | timestamp | |

## UI Structure

### Routes

```
/dashboard                     — Overview dashboard
/partners                      — Partner organizations list
/partners/create               — Add new partner (wizard or template)
/partners/{id}                 — Partner detail & policy management
/partners/import               — Bulk CSV import
/guests                        — Guest users list
/guests/invite                 — Send B2B invitation
/guests/{id}                   — Guest user detail
/templates                     — Partner onboarding templates
/templates/create              — Create template
/templates/{id}/edit           — Edit template
/settings                      — App settings (sync config, default policies)
/activity                      — Activity/audit log
```

### Dashboard

- Partner summary cards: total, by category, MFA trust enabled vs disabled
- Guest user stats: total, pending invitations, inactive (no sign-in in 90 days)
- Recent activity feed (last 20 actions)
- Policy compliance overview: partners inheriting defaults vs custom, drift warnings

### Partner List

- Sortable/filterable data table: Name, Domain, Category, Owner, MFA Trust, B2B Inbound, B2B Outbound, Last Synced
- Quick filters by category, MFA trust status, owner
- Search by name/domain
- Bulk actions: export CSV, bulk policy update

### Partner Detail

- Header with name, domain, category, owner
- Policy panel with visual toggle cards for each setting (B2B inbound/outbound, MFA trust, device trust, direct connect, auto user consent)
- Guest users tab: guests from this partner
- Activity tab: actions on this partner
- Notes section
- Danger zone: remove partner config

### Partner Onboarding Wizard

1. Enter tenant ID or domain → resolve via Graph → show org name
2. Select template OR configure manually
3. Review all settings
4. Apply via Graph API → show result

### Guest Management

- Data table: Name, Email, Partner Org, Status, Invited By, Invited At, Last Sign-In
- Filters by partner org, invitation status, activity level
- Invite form: email, redirect URL, custom message, send email toggle
- Bulk invite via CSV

## Error Handling

- Graph API 4xx/5xx: catch and map to user-friendly messages
- Rate limiting (429): exponential backoff in queue workers
- Token expiration: automatic refresh
- Sync failures: log, continue with remaining items, surface sync health on dashboard
- Bulk operation partial failures: per-item success/failure tracking with summary

## Security

- All Graph API tokens stored server-side only (encrypted via Laravel env)
- RBAC enforced at route middleware and controller level
- All write operations logged to activity_log
- CSRF protection via Inertia
- Input validation via Laravel Form Requests
- Tenant ID format validation (GUID) before Graph API calls

## Testing

- Unit tests: service classes with mocked Graph API responses (Pest + Mockery)
- Feature tests: full HTTP request lifecycle for controllers
- Graph API mocking via Laravel `Http::fake()`
- No live Graph integration tests in CI — manual validation with test tenant only

## Multi-Tenant Readiness

Architecture supports future expansion to multiple M365 tenants:
- `partner_organizations` and `guest_users` can add a `managed_tenant_id` column
- `MicrosoftGraphService` can accept a tenant context parameter
- Not implemented in v1 to avoid premature complexity

## References

- [Cross-tenant access settings API overview](https://learn.microsoft.com/en-us/graph/api/resources/crosstenantaccesspolicy-overview?view=graph-rest-1.0)
- [Partner configuration resource type](https://learn.microsoft.com/en-us/graph/api/resources/crosstenantaccesspolicyconfigurationpartner?view=graph-rest-1.0)
- [Create invitation API](https://learn.microsoft.com/en-us/graph/api/invitation-post?view=graph-rest-1.0)
- [List users with guest filter](https://learn.microsoft.com/en-us/graph/api/user-list?view=graph-rest-1.0)
- [Manage cross-tenant access for B2B](https://learn.microsoft.com/en-us/entra/external-id/cross-tenant-access-settings-b2b-collaboration)
- [B2B collaboration API customization](https://learn.microsoft.com/en-us/entra/external-id/customize-invitation-api)
