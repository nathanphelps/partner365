# Access Reviews for Guest Users and Partner Organizations

GitHub Issue: #4

## Overview

Automated periodic access reviews to certify continued guest user access and partner organization relationships. Reviewers (operators) periodically attest whether external users and partner policies still need to remain active. Admins define review schedules and criteria; operators execute reviews.

## Scope

- **Guest user reviews** — Certify continued access for individual guest users, optionally scoped to a specific partner org.
- **Partner organization reviews** — Advisory reviews of whether entire partner relationships should continue. Always flag-only (no auto-remediation due to high impact).

## RBAC

- **Admins** create, configure, and delete review definitions. Can trigger remediation application.
- **Operators** act as reviewers — submit approve/deny decisions on individual subjects.
- **Viewers** have read-only access to review status and compliance metrics.

## Data Model

### New Tables

**`access_reviews`** — Review definitions created by admins.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| title | string | |
| description | text nullable | |
| review_type | enum | `guest_users`, `partner_organizations` |
| scope_partner_id | FK nullable | Scopes guest review to a specific partner |
| recurrence_type | enum | `one_time`, `recurring` |
| recurrence_interval_days | int nullable | 30, 60, 90, etc. |
| remediation_action | enum | `flag_only`, `disable`, `remove` |
| reviewer_user_id | FK | Operator assigned to review |
| created_by_user_id | FK | Admin who created it |
| graph_definition_id | string nullable | Graph API definition ID for sync |
| next_review_at | timestamp nullable | |
| created_at, updated_at | timestamps | |

**`access_review_instances`** — Individual review cycles.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| access_review_id | FK | |
| status | enum | `pending`, `in_progress`, `completed`, `expired` |
| started_at | timestamp | |
| due_at | timestamp | |
| completed_at | timestamp nullable | |

**`access_review_decisions`** — Per-subject decisions within an instance.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| access_review_instance_id | FK | |
| subject_type | enum | `guest_user`, `partner_organization` |
| subject_id | bigint | Polymorphic (guest_user.id or partner_organization.id) |
| decision | enum | `approve`, `deny`, `pending` |
| justification | text nullable | |
| decided_by_user_id | FK nullable | |
| decided_at | timestamp nullable | |
| remediation_applied | boolean | default false |
| remediation_applied_at | timestamp nullable | |

### New Enums

- `ReviewType`: `guest_users`, `partner_organizations`
- `RecurrenceType`: `one_time`, `recurring`
- `RemediationAction`: `flag_only`, `disable`, `remove`
- `ReviewInstanceStatus`: `pending`, `in_progress`, `completed`, `expired`
- `ReviewDecision`: `approve`, `deny`, `pending`

### New ActivityAction Values

`AccessReviewCreated`, `AccessReviewCompleted`, `AccessReviewDecisionMade`, `AccessReviewRemediationApplied`

## Backend Architecture

### AccessReviewService

Wraps Graph API calls to `identityGovernance/accessReviews/definitions` and related endpoints.

- `createDefinition($config)` — Creates review in Graph API + local DB
- `listDefinitions()` — Fetch all review definitions
- `getDefinition($id)` — Fetch single definition with instances
- `deleteDefinition($id)` — Remove from Graph API + local DB
- `listInstances($definitionId)` — Fetch review instances
- `getDecisions($instanceId)` — Fetch decisions for an instance
- `submitDecision($decisionId, $decision, $justification)` — Operator submits approve/deny
- `applyRemediations($instanceId)` — Applies deny decisions per remediation config

### AccessReviewController

- Admin actions (`isAdmin()`): `create`, `store`, `destroy`
- Operator actions (`canManage()`): `submitDecision`, `applyRemediations`
- Read actions (all authenticated): `index`, `show`

### sync:access-reviews Command

Runs on the same 15-minute schedule as existing sync commands.

- Syncs instance statuses and decisions from Graph API to local DB
- Auto-generates new instances for recurring reviews when `next_review_at` has passed
- Logs sync via `SyncLog` model

### Remediation Logic

Applied via `AccessReviewService::applyRemediations()`:

- Guest `disable`: calls `GuestUserService::disableUser()`
- Guest `remove`: calls `GuestUserService::deleteUser()`
- Guest `flag_only`: marks as flagged, no Graph API action
- Partner reviews: always flag-only, no auto-remediation
- All actions logged via `ActivityLogService`

### Notifications

Relies on Microsoft's built-in access review email notifications configured via the Graph API definition. No duplicate notification system in Laravel.

## Routes

```
GET    /access-reviews                              → index (all roles)
GET    /access-reviews/create                       → create form (admin)
POST   /access-reviews                              → store (admin)
GET    /access-reviews/{id}                         → show definition + instances (all roles)
DELETE /access-reviews/{id}                          → destroy (admin)
GET    /access-reviews/{id}/instances/{instanceId}  → instance detail (all roles)
POST   /access-reviews/decisions/{id}               → submit decision (operator+)
POST   /access-reviews/instances/{id}/apply         → apply remediations (admin)
```

## Frontend

### Pages (`resources/js/pages/access-reviews/`)

**Index.vue** — List of all review definitions.
- Columns: title, type (guest/partner), recurrence, reviewer, latest instance status, compliance %
- Filters: type, status
- "Create Review" button (admin only)
- Summary stats bar: total active reviews, overdue count, overall compliance %

**Create.vue** — Admin form to define a new review.
- Title, description
- Review type selector (guest users / partner organizations)
- Scope selector (all guests or filtered by partner org — guest type only)
- Recurrence: one-time or recurring with interval picker
- Remediation action: flag only / disable / remove (locked to flag-only for partner type)
- Reviewer assignment (dropdown of operators)

**Show.vue** — Review definition detail.
- Config summary at top
- Table of instances with status badges, date range, completion %
- Click through to instance detail

**Instance.vue** — Instance detail with decision table.
- Columns: subject name, email/domain, current status, decision, justification, decided by
- Operator can submit decisions inline (approve/deny with optional justification)
- Admin can trigger "Apply Remediations" for all deny decisions
- Compliance summary: X of Y approved, Z denied, W pending

### Components

- `AccessReviewStatusBadge.vue` — Reusable badge for review/instance status

### Dashboard Integration

"Access Reviews" card on the main dashboard showing overdue reviews count and overall compliance %.

## Testing

### AccessReviewServiceTest

Graph API integration tests with `Http::fake()`: create/list/delete definitions, fetch instances and decisions, submit decisions, apply remediations.

### AccessReviewControllerTest

HTTP tests: RBAC enforcement (admin create/delete, operator decisions, viewer read-only), store validation, correct Inertia props.

### SyncAccessReviewsTest

Sync command: syncs instance statuses, auto-generates recurring instances, handles Graph API errors.

### AccessReviewRemediationTest

Remediation logic: disable/remove/flag-only actions call correct services, partner reviews never auto-remediate, activity log entries created.

### Mock Pattern

```php
Http::fake([
    'login.microsoftonline.com/*' => Http::response([...]),
    'graph.microsoft.com/*/identityGovernance/accessReviews/*' => Http::response([...]),
]);
```

## Graph API Endpoints

- `POST /identityGovernance/accessReviews/definitions` — Create review definition
- `GET /identityGovernance/accessReviews/definitions` — List definitions
- `GET /identityGovernance/accessReviews/definitions/{id}` — Get definition
- `DELETE /identityGovernance/accessReviews/definitions/{id}` — Delete definition
- `GET /identityGovernance/accessReviews/definitions/{id}/instances` — List instances
- `GET /identityGovernance/accessReviews/definitions/{id}/instances/{id}/decisions` — List decisions
- `PATCH /identityGovernance/accessReviews/definitions/{id}/instances/{id}/decisions/{id}` — Submit decision

## References

- [Access reviews for external users](https://learn.microsoft.com/en-us/entra/id-governance/access-reviews-external-users)
- [Access reviews API](https://learn.microsoft.com/en-us/graph/api/resources/accessreviewsv2-overview?view=graph-rest-1.0)
