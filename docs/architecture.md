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
│  │  GuestUserService (invitations + users)         ││
│  │  TenantResolverService (tenant lookup)          ││
│  │  ActivityLogService (audit trail)               ││
│  │  AccessReviewService (access review lifecycle) ││
│  └──────┬──────────────────────────────────────────┘│
│         │                                            │
│  ┌──────▼──────┐  ┌───────────────────────────────────────┐ │
│  │  Eloquent   │  │  Scheduled Commands                    │ │
│  │  Models     │  │  sync:partners        (every 15 min)  │ │
│  │             │  │  sync:guests          (every 15 min)  │ │
│  │             │  │  sync:access-reviews  (every 15 min)  │ │
│  └──────┬──────┘  └───────────────────────────────────────┘ │
└─────────┼───────────────────────────────────────────┘
          │                        │
┌─────────▼─────────┐  ┌──────────▼──────────────────┐
│  SQLite / Postgres │  │  Microsoft Graph API v1.0   │
│  (Local Cache)     │  │  (Source of Truth)           │
└────────────────────┘  └─────────────────────────────┘
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
└── two_factor_* (Fortify 2FA columns)

partner_organizations
├── id, tenant_id (unique UUID)
├── display_name, domain, category (enum)
├── owner_user_id (FK → users)
├── notes
├── b2b_inbound_enabled, b2b_outbound_enabled
├── mfa_trust_enabled, device_trust_enabled
├── direct_connect_inbound_enabled, direct_connect_outbound_enabled
├── tenant_restrictions_enabled
├── tenant_restrictions_json (JSON: app/user targeting config)
├── raw_policy_json (full Graph API response)
├── last_synced_at
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

activity_log
├── id, user_id (FK → users)
├── action (enum: partner_created, guest_invited, access_review_created, etc.)
├── subject_type, subject_id (polymorphic)
├── description, details (JSON)
└── created_at
```

### Relationships

- `PartnerOrganization` → belongs to `User` (owner), has many `GuestUser`
- `GuestUser` → belongs to `PartnerOrganization` (nullable), belongs to `User` (invited_by)
- `PartnerTemplate` → belongs to `User` (created_by)
- `AccessReview` → belongs to `User` (reviewer, created_by), belongs to `PartnerOrganization` (scope), has many `AccessReviewInstance`
- `AccessReviewInstance` → belongs to `AccessReview`, has many `AccessReviewDecision`
- `AccessReviewDecision` → belongs to `AccessReviewInstance`, belongs to `User` (decided_by)
- `ActivityLog` → belongs to `User`, morphs to subject

### Auto Partner Linking

Guest users are automatically linked to partner organizations by matching the guest's email domain against `partner_organizations.domain`. This happens both during invitation and during background sync.

## Key Design Decisions

### No Microsoft Graph SDK

Graph API is called directly via Laravel's `Http` facade (Guzzle wrapper). This keeps dependencies lean, makes test mocking trivial with `Http::fake()`, and avoids the complexity of the official SDK for the subset of endpoints we use.

### Token Caching

The OAuth2 client credentials token is cached for 3500 seconds (just under the 3600-second expiry). This prevents redundant token requests across the 15-minute sync cycles and concurrent web requests.

### Server-Side Only

All Graph API calls happen server-side. The Vue frontend never sees tokens or makes direct API calls. The only AJAX call from the frontend is the tenant resolution during partner onboarding, which goes through a Laravel controller endpoint.

### Single-Container Deployment

The Docker image runs FrankenPHP (via Laravel Octane) with supervisord managing three processes: the web server, queue worker, and task scheduler. This keeps deployment simple — one container with no external dependencies (SQLite by default). For higher-scale deployments, the queue worker and scheduler can be split into separate containers using the same image with different entrypoint commands.

### Multi-Tenant Ready

The architecture supports adding a `managed_tenant_id` column in the future to manage multiple Entra tenants from a single Partner365 instance. Not implemented in v1.
