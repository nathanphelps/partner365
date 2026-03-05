# Architecture

## Overview

Partner365 is a monolithic Laravel 12 + Vue 3 application using Inertia.js for server-driven SPA behavior. All Microsoft Graph API calls happen server-side — tokens and secrets never reach the browser.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────┐
│                    Browser                           │
│  Vue 3 + Inertia.js + shadcn-vue + Tailwind CSS    │
└─────────────────────┬───────────────────────────────┘
                      │ Inertia Protocol (JSON props)
┌─────────────────────▼───────────────────────────────┐
│                 Laravel 12                            │
│  ┌─────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │ Controllers  │  │  Middleware   │  │  Requests  │ │
│  │ (Inertia)   │  │  (RBAC)      │  │ (Validate) │ │
│  └──────┬──────┘  └──────────────┘  └────────────┘ │
│         │                                            │
│  ┌──────▼──────────────────────────────────────────┐│
│  │              Service Layer                       ││
│  │  MicrosoftGraphService (HTTP client + tokens)   ││
│  │  CrossTenantPolicyService (partner policies)    ││
│  │  CollaborationSettingsService (auth policy)     ││
│  │  GuestUserService (invitations + users + access) ││
│  │  TenantResolverService (tenant lookup)          ││
│  │  ActivityLogService (audit trail)               ││
│  │  AccessReviewService (access review lifecycle) ││
│  │  ConditionalAccessPolicyService (CA sync)     ││
│  │  SensitivityLabelService (label sync + mapping)││
│  │  EntitlementService (access packages + Graph)  ││
│  │  TrustScoreService (domain reputation scoring) ││
│  │  DnsLookupService (DNS record queries)         ││
│  │  SharePointSiteService (site sync + perms)    ││
│  │  FaviconService (partner favicon fetch+cache)  ││
│  │                                                ││
│  │  ComplianceReportController (aggregation only) ││
│  └──────┬──────────────────────────────────────────┘│
│         │                                            │
│  ┌──────────────────────────────────────────────────┐│
│  │         Syslog / SIEM Layer                       ││
│  │  ActivityLogObserver → ForwardToSyslog (queued)  ││
│  │  CefFormatter (CEF string builder)               ││
│  │  SyslogTransport (UDP / TCP / TLS)               ││
│  └──────────────────────────────────────────────────┘│
│         │                                            │
│  ┌──────▼──────┐  ┌───────────────────────────────────────┐ │
│  │  Eloquent   │  │  Scheduled Commands                    │ │
│  │  Models     │  │  sync:partners        (every 15 min)  │ │
│  │             │  │  sync:guests          (every 15 min)  │ │
│  │             │  │  sync:access-reviews  (every 15 min)  │ │
│  │             │  │  sync:entitlements    (every 15 min)  │ │
│  │             │  │  sync:conditional-    (every 15 min)  │ │
│  │             │  │    access-policies                    │ │
│  │             │  │  sync:sensitivity-   (every 15 min)  │ │
│  │             │  │    labels                             │ │
│  │             │  │  sync:sharepoint-   (every 15 min)  │ │
│  │             │  │    sites                              │ │
│  │             │  │  sync:favicons        (daily)         │ │
│  │             │  │  score:partners       (daily)         │ │
│  └──────┬──────┘  └───────────────────────────────────────┘ │
└─────────┼───────────────────────────────────────────┘
          │                        │
┌─────────▼─────────┐  ┌──────────▼──────────────────┐  ┌───────────────────┐
│  SQLite / Postgres │  │  Microsoft Graph API v1.0   │  │  Syslog / SIEM    │
│  (Local Cache)     │  │  (Source of Truth)           │  │  (LogRhythm, etc) │
└────────────────────┘  └─────────────────────────────┘  └───────────────────┘
```

## Data Model

### Hybrid Approach

The app uses a **write-through + background sync** pattern:

- **Writes** go to Graph API first, then update the local database on success
- **Reads** come from the local database for fast page loads
- **Background sync** runs every 15 minutes to reconcile local data with Graph API

This avoids hitting Graph API rate limits on every page load while keeping data reasonably fresh.

### Database Schema

```
users
├── id, name, email, password
├── role (enum: admin, operator, viewer)
├── entra_id (nullable, unique — Entra object ID for SSO matching)
├── approved_at, approved_by
└── two_factor_* (Fortify 2FA columns)

partner_organizations
├── id, tenant_id (unique UUID)
├── display_name, domain, favicon_path (cached favicon), category (enum)
├── owner_user_id (FK → users)
├── notes
├── b2b_inbound_enabled, b2b_outbound_enabled
├── mfa_trust_enabled, device_trust_enabled
├── direct_connect_inbound_enabled, direct_connect_outbound_enabled
├── tenant_restrictions_enabled
├── tenant_restrictions_json (JSON: app/user targeting config)
├── raw_policy_json (full Graph API response)
├── last_synced_at
├── trust_score (0-100, nullable)
├── trust_score_breakdown (JSON: per-signal pass/fail and points)
├── trust_score_calculated_at
└── timestamps

guest_users
├── id, entra_user_id (unique)
├── email, display_name, user_principal_name
├── partner_organization_id (FK → partner_organizations, nullable)
├── invited_by_user_id (FK → users, nullable)
├── invitation_status (enum: pending_acceptance, accepted, failed)
├── invited_at, last_sign_in_at, account_enabled
├── last_synced_at
└── timestamps

partner_templates
├── id, name, description
├── policy_config (JSON: boolean flags for each policy toggle)
├── created_by_user_id (FK → users)
└── timestamps

access_reviews
├── id, title, description
├── review_type (enum: guest_users, partner_organizations)
├── scope_partner_id (FK → partner_organizations, nullable)
├── recurrence_type (enum: one_time, recurring)
├── recurrence_interval_days (nullable)
├── remediation_action (enum: flag_only, disable, remove)
├── reviewer_user_id (FK → users)
├── created_by_user_id (FK → users)
├── graph_definition_id (nullable)
├── next_review_at (nullable)
└── timestamps

access_review_instances
├── id, access_review_id (FK → access_reviews)
├── status (enum: pending, in_progress, completed, expired)
├── started_at, due_at, completed_at
└── timestamps

access_review_decisions
├── id, access_review_instance_id (FK → access_review_instances)
├── subject_type (guest_user, partner_organization)
├── subject_id
├── decision (enum: pending, approve, deny)
├── justification (nullable)
├── decided_by_user_id (FK → users, nullable)
├── decided_at (nullable)
├── remediation_applied (boolean)
└── timestamps

conditional_access_policies
├── id, policy_id (unique, Graph API object ID)
├── display_name, state (enabled/disabled/enabledForReportingButNotEnforced)
├── guest_or_external_user_types (comma-separated)
├── external_tenant_scope (all/specific)
├── external_tenant_ids (JSON, nullable)
├── target_applications
├── grant_controls (JSON), session_controls (JSON)
├── raw_policy_json (JSON)
├── synced_at
└── timestamps

conditional_access_policy_partner (pivot)
├── id
├── conditional_access_policy_id (FK → conditional_access_policies)
├── partner_organization_id (FK → partner_organizations)
├── matched_user_type
└── timestamps

sensitivity_labels
├── id, label_id (unique, Graph API label ID)
├── name, description, tooltip
├── color (hex), priority (integer)
├── protection_type (encryption/watermark/header_footer/none)
├── scope (JSON array: files_emails, sites_groups)
├── is_active (boolean)
├── parent_id (FK → sensitivity_labels, nullable, self-referencing)
├── raw_json (JSON)
├── synced_at
└── timestamps

sensitivity_label_policies
├── id, policy_id (unique, Graph API policy ID)
├── name
├── target_type (all_users/specific_groups/all_users_and_guests)
├── target_groups (JSON, nullable)
├── labels (JSON array of label IDs)
├── raw_json (JSON)
├── synced_at
└── timestamps

sensitivity_label_partner (pivot)
├── id
├── sensitivity_label_id (FK → sensitivity_labels)
├── partner_organization_id (FK → partner_organizations)
├── matched_via (label_policy/site_assignment)
├── policy_name, site_name (nullable context strings)
└── timestamps

site_sensitivity_labels
├── id, site_id (unique, Graph site ID)
├── site_name, site_url
├── sensitivity_label_id (FK → sensitivity_labels, nullable)
├── external_sharing_enabled (boolean)
├── synced_at
└── timestamps

sharepoint_sites
├── id, site_id (unique, Graph API site ID)
├── display_name, url, description
├── sensitivity_label_id (FK → sensitivity_labels, nullable)
├── external_sharing_capability (string)
├── owner_display_name, owner_email
├── storage_used_bytes, member_count
├── last_activity_at
├── raw_json (JSON)
├── synced_at
└── timestamps

sharepoint_site_permissions
├── id
├── sharepoint_site_id (FK → sharepoint_sites)
├── guest_user_id (FK → guest_users)
├── role (string)
├── granted_via (direct/sharing_link/group_membership)
└── timestamps
(Unique constraint on [sharepoint_site_id, guest_user_id, role, granted_via])

access_package_catalogs
├── id, graph_id (unique, nullable)
├── display_name, description
├── is_default (boolean)
├── last_synced_at
└── timestamps

access_packages
├── id, graph_id (unique, nullable)
├── catalog_id (FK → access_package_catalogs)
├── partner_organization_id (FK → partner_organizations)
├── display_name, description
├── duration_days (default: 90)
├── approval_required (boolean)
├── approver_user_id (FK → users, nullable)
├── is_active (boolean)
├── created_by_user_id (FK → users)
├── last_synced_at
└── timestamps

access_package_resources
├── id, access_package_id (FK → access_packages)
├── resource_type (enum: group, sharepoint_site)
├── resource_id (Graph object ID)
├── resource_display_name
├── graph_id (nullable)
└── timestamps

access_package_assignments
├── id, graph_id (unique, nullable)
├── access_package_id (FK → access_packages)
├── target_user_email, target_user_id (nullable)
├── status (enum: pending_approval, approved, denied, delivering, delivered, expired, revoked)
├── approved_by_user_id (FK → users, nullable)
├── expires_at, requested_at, approved_at, delivered_at
├── justification (nullable)
├── last_synced_at
└── timestamps

activity_log
├── id, user_id (FK → users, nullable for system events)
├── action (enum: partner_created, guest_invited, user_logged_in, login_failed,
│          password_changed, two_factor_enabled, profile_updated, template_updated,
│          sync_completed, graph_connection_tested, consent_granted, etc.)
├── subject_type, subject_id (polymorphic)
├── description, details (JSON)
└── created_at

settings
├── id, group (e.g. 'graph', 'sync', 'syslog', 'sso')
├── key (e.g. 'host', 'port', 'transport', 'enabled', 'group_mappings')
├── value (string), encrypted (boolean)
└── timestamps
(Unique constraint on group + key)
```

### Relationships

- `PartnerOrganization` → belongs to `User` (owner), has many `GuestUser`, belongs to many `ConditionalAccessPolicy`, belongs to many `SensitivityLabel`
- `ConditionalAccessPolicy` → belongs to many `PartnerOrganization` (via pivot with `matched_user_type`)
- `SensitivityLabel` → belongs to many `PartnerOrganization` (via pivot with `matched_via`, `policy_name`, `site_name`), belongs to `SensitivityLabel` (parent), has many `SensitivityLabel` (children)
- `SensitivityLabelPolicy` → standalone (stores label policy definitions with target type and assigned labels)
- `SiteSensitivityLabel` → belongs to `SensitivityLabel` (tracks per-site label assignments)
- `SharePointSite` → belongs to `SensitivityLabel`, has many `SharePointSitePermission`, has many `GuestUser` (through permissions)
- `SharePointSitePermission` → belongs to `SharePointSite`, belongs to `GuestUser`
- `GuestUser` → belongs to `PartnerOrganization` (nullable), belongs to `User` (invited_by), has many `SharePointSitePermission`
- `PartnerTemplate` → belongs to `User` (created_by)
- `AccessReview` → belongs to `User` (reviewer, created_by), belongs to `PartnerOrganization` (scope), has many `AccessReviewInstance`
- `AccessReviewInstance` → belongs to `AccessReview`, has many `AccessReviewDecision`
- `AccessReviewDecision` → belongs to `AccessReviewInstance`, belongs to `User` (decided_by)
- `AccessPackageCatalog` → has many `AccessPackage`
- `AccessPackage` → belongs to `AccessPackageCatalog` (catalog), `PartnerOrganization`, `User` (approver, created_by), has many `AccessPackageResource`, `AccessPackageAssignment`
- `AccessPackageResource` → belongs to `AccessPackage`
- `AccessPackageAssignment` → belongs to `AccessPackage`, `User` (approved_by)
- `ActivityLog` → belongs to `User`, morphs to subject

### Auto Partner Linking

Guest users are automatically linked to partner organizations by matching the guest's email domain against `partner_organizations.domain`. This happens both during invitation and during background sync.

## Key Design Decisions

### No Microsoft Graph SDK

Graph API is called directly via Laravel's `Http` facade (Guzzle wrapper). This keeps dependencies lean, makes test mocking trivial with `Http::fake()`, and avoids the complexity of the official SDK for the subset of endpoints we use.

### Token Caching

The OAuth2 client credentials token is cached for 3500 seconds (just under the 3600-second expiry). This prevents redundant token requests across the 15-minute sync cycles and concurrent web requests.

### Server-Side Only

All Graph API calls happen server-side. The Vue frontend never sees tokens or makes direct API calls. AJAX calls from the frontend go through Laravel controller endpoints (e.g., tenant resolution during partner onboarding, guest access visibility tabs).

### Single-Container Deployment

The Docker image runs FrankenPHP (via Laravel Octane) with supervisord managing three processes: the web server, queue worker, and task scheduler. This keeps deployment simple — one container with no external dependencies (SQLite by default). For higher-scale deployments, the queue worker and scheduler can be split into separate containers using the same image with different entrypoint commands.

### Multi-Tenant Ready

The architecture supports adding a `managed_tenant_id` column in the future to manage multiple Entra tenants from a single Partner365 instance. Not implemented in v1.
