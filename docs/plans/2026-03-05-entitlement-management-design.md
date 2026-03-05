# Entitlement Management MVP Design

**Date:** 2026-03-05
**Issue:** #5 — Entitlement Management: access packages for external users
**Status:** Approved

## Overview

Self-service access request workflows for external partner users. Instead of manually inviting guests, partners request access packages that bundle group memberships and SharePoint site access with configurable duration and single-stage approval.

## Decisions

- **Scope:** Focused MVP — access packages, basic assignment policies, single-stage approval
- **Data relationship:** Access packages linked to existing PartnerOrganization records
- **Approval:** Single-stage (one approver per package)
- **Expiration:** Configurable duration (30/60/90/custom days), auto-expire
- **Resources:** Groups + SharePoint sites
- **Architecture:** Graph-first with local cache (write-through + sync reconciliation)
- **Sync:** Background command every 15 minutes, matching existing pattern

## Data Model

### AccessPackageCatalog

Container for access packages (maps to Graph catalog).

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| graph_id | string, nullable | Graph API ID |
| display_name | string | |
| description | text, nullable | |
| is_default | boolean | MVP uses default catalog |
| last_synced_at | timestamp, nullable | |
| timestamps | | |

### AccessPackage

Bundled resource access that can be requested by external users.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| graph_id | string, nullable | Graph API ID |
| catalog_id | FK → access_package_catalogs | |
| partner_organization_id | FK → partner_organizations | Scoped to partner |
| display_name | string | |
| description | text, nullable | |
| duration_days | integer | 30, 60, 90, or custom |
| approval_required | boolean | |
| approver_user_id | FK → users, nullable | Single-stage approver |
| is_active | boolean | |
| created_by_user_id | FK → users | |
| last_synced_at | timestamp, nullable | |
| timestamps | | |

### AccessPackageResource

Resources bundled in an access package.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| access_package_id | FK → access_packages | |
| resource_type | enum | group, sharepoint_site |
| resource_id | string | Graph object ID |
| resource_display_name | string | Cached display name |
| graph_id | string, nullable | Graph resource role scope ID |
| timestamps | | |

### AccessPackageAssignment

A user's granted (or requested) access to a package.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| graph_id | string, nullable | Graph API ID |
| access_package_id | FK → access_packages | |
| target_user_email | string | External user email |
| target_user_id | string, nullable | Graph user ID once resolved |
| status | enum | pending_approval, approved, denied, delivering, delivered, expired, revoked |
| approved_by_user_id | FK → users, nullable | |
| expires_at | timestamp, nullable | |
| requested_at | timestamp | |
| approved_at | timestamp, nullable | |
| delivered_at | timestamp, nullable | |
| justification | text, nullable | |
| last_synced_at | timestamp, nullable | |
| timestamps | | |

### New Enums

- **AccessPackageResourceType:** group, sharepoint_site
- **AssignmentStatus:** pending_approval, approved, denied, delivering, delivered, expired, revoked

### ActivityAction additions

AccessPackageCreated, AccessPackageUpdated, AccessPackageDeleted, AssignmentRequested, AssignmentApproved, AssignmentDenied, AssignmentRevoked

## Service Layer

### EntitlementService

Graph-first operations with local caching.

**Catalog operations:**
- `createCatalog()` / `getCatalogs()` — Manage catalogs via Graph API

**Package operations:**
- `createAccessPackage(partner, data)` — Creates in Graph + local DB, sets up assignment policy, adds resources, ensures connected organization exists
- `updateAccessPackage()` — Update package config in Graph + local
- `deleteAccessPackage()` — Remove from Graph + local
- `addResource()` / `removeResource()` — Manage resource role scopes

**Assignment operations:**
- `requestAssignment(package, email, justification)` — POST assignment request to Graph
- `approveAssignment()` / `denyAssignment()` — Process approval
- `revokeAssignment()` — Revoke active access
- `listAssignments(package)` — Get assignments for a package

**Connected organization:**
- Checks if partner's tenant is already a connected org in Graph
- Creates one if not: POST `/identityGovernance/entitlementManagement/connectedOrganizations`

**Resource discovery:**
- `listGroups()` — Fetch available groups from Graph
- `listSharePointSites()` — Fetch available SharePoint sites

**Sync:**
- `syncAccessPackages()` — Reconcile packages with Graph
- `syncAssignments()` — Reconcile assignment statuses, mark expired

### Graph API Endpoints Used

- `POST/GET/PATCH/DELETE /identityGovernance/entitlementManagement/accessPackages`
- `POST/GET /identityGovernance/entitlementManagement/assignmentRequests`
- `GET /identityGovernance/entitlementManagement/assignments`
- `POST/GET /identityGovernance/entitlementManagement/connectedOrganizations`
- `POST/GET /identityGovernance/entitlementManagement/catalogs`
- `GET /groups` — Resource discovery
- `GET /sites` — SharePoint site discovery

## Controller & Routes

### EntitlementController

| Route | Method | Action | RBAC |
|-------|--------|--------|------|
| GET /entitlements | index | List packages | all |
| GET /entitlements/create | create | Create form | admin |
| POST /entitlements | store | Create package | admin |
| GET /entitlements/{id} | show | Package detail + assignments | all |
| PATCH /entitlements/{id} | update | Update package | admin |
| DELETE /entitlements/{id} | destroy | Delete package | admin |
| POST /entitlements/{id}/assignments | | Create assignment | operator+ |
| POST /entitlements/{id}/assignments/{a}/approve | | Approve | operator+ |
| POST /entitlements/{id}/assignments/{a}/deny | | Deny | operator+ |
| POST /entitlements/{id}/assignments/{a}/revoke | | Revoke | operator+ |
| GET /entitlements/groups | | JSON: available groups | operator+ |
| GET /entitlements/sharepoint-sites | | JSON: available sites | operator+ |

## Frontend

### Vue Pages

1. **entitlements/Index.vue** — List with search, filter by partner/status, summary stats
2. **entitlements/Create.vue** — Multi-step form: select partner -> add resources (groups/SharePoint) -> configure policy (duration, approver) -> review & create
3. **entitlements/Show.vue** — Package details, resource list, assignments table with approve/deny/revoke actions

### Navigation

Add "Entitlements" to sidebar between "Access Reviews" and "Activity" using `Package` icon from lucide-vue-next.

### TypeScript Types

New file: `resources/js/types/entitlement.ts` with interfaces for AccessPackageCatalog, AccessPackage, AccessPackageResource, AccessPackageAssignment.

## Sync Command

`sync:entitlements` — Scheduled every 15 minutes in `routes/console.php`.

1. Fetch all access packages from Graph
2. Update/create local records
3. Fetch all assignments, update statuses
4. Mark expired assignments
5. Log sync activity

## Testing

- **EntitlementServiceTest** — Unit tests with `Http::fake()` for all Graph endpoints
- **EntitlementControllerTest** — Feature tests for CRUD + assignment workflows
- Mock Graph responses for catalogs, packages, resources, assignments, connected orgs
- Framework: Pest PHP with in-memory SQLite

## Error Handling

- Graph API failures surface as user-friendly flash messages via Inertia
- Partial failures (package created but resource add fails) are logged and shown
- Sync gracefully handles missing/deleted Graph resources
