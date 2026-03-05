# Sensitivity Label Reporting & Partner Impact - Design

## Overview

Add read-only visibility into Microsoft Information Protection sensitivity labels and their impact on partner organizations, mirroring the existing conditional access policy pattern. Track labels across both file/email and site/group scopes, map partner impact via label policy assignments and site-level label assignments, and integrate into compliance scoring.

## Data Model

### `sensitivity_labels` table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | Auto-increment |
| label_id | string | Graph API label ID |
| name | string | Display name |
| description | text nullable | Label description |
| color | string nullable | Label color code |
| tooltip | string nullable | Tooltip text |
| scope | json | Array of scopes: `files_emails`, `sites_groups` |
| priority | integer | Order/rank from Graph |
| is_active | boolean | Whether label is currently active |
| parent_label_id | bigint nullable FK | Self-referencing for sub-labels |
| protection_type | string | `encryption`, `watermark`, `header_footer`, `none` |
| raw_json | json | Full Graph API response |
| last_synced_at | timestamp | Last sync time |
| timestamps | | created_at, updated_at |

### `sensitivity_label_policies` table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | Auto-increment |
| policy_id | string | Graph API policy ID |
| name | string | Policy display name |
| target_type | string | `all_users`, `specific_groups`, `all_users_and_guests` |
| target_groups | json | Array of group IDs targeted |
| labels | json | Array of label IDs published by this policy |
| raw_json | json | Full Graph API response |
| last_synced_at | timestamp | Last sync time |
| timestamps | | created_at, updated_at |

### `sensitivity_label_partner` pivot table

| Column | Type | Description |
|--------|------|-------------|
| sensitivity_label_id | bigint FK | References sensitivity_labels |
| partner_organization_id | bigint FK | References partner_organizations |
| matched_via | string | `label_policy` or `site_assignment` |
| policy_name | string nullable | Which label policy created this mapping |
| site_name | string nullable | Which site if matched via site assignment |

### `site_sensitivity_labels` table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | Auto-increment |
| site_id | string | Graph API site ID |
| site_name | string | Site display name |
| site_url | string | Site URL |
| sensitivity_label_id | bigint FK | References sensitivity_labels |
| external_sharing_enabled | boolean | Whether site allows external sharing |
| last_synced_at | timestamp | Last sync time |
| timestamps | | created_at, updated_at |

## Service Layer

### `SensitivityLabelService`

Mirrors `ConditionalAccessPolicyService` pattern.

**Methods:**

- `fetchLabelsFromGraph()` — `GET /security/informationProtection/sensitivityLabels` to pull all label definitions
- `fetchLabelPoliciesFromGraph()` — Fetch label policy assignments and their target groups
- `fetchSiteLabelAssignments()` — Iterate SharePoint sites via `GET /sites?search=*`, check `GET /sites/{id}/sensitivityLabel` for each. Only process sites with external sharing enabled.
- `parseLabel()` — Extract protection type, scope, parent/child hierarchy from raw Graph response
- `buildPartnerMappings()` — Two mapping paths:
  1. **Policy-based:** Label policy targets all users including guests -> all partners. Scoped to groups -> find partners whose guests are members via `GuestUserService`
  2. **Site-based:** Labeled site with external sharing enabled -> find partners whose guests have access to that site
- `getUncoveredPartners()` — Partners with no sensitivity label coverage

### `SyncSensitivityLabels` command

- Scheduled every 15 minutes
- Three-phase sync: labels -> policies -> site assignments -> rebuild partner mappings
- Logs via `ActivityLogService::logSystem()` with `ActivityAction::SensitivityLabelsSynced`
- Creates `SyncLog` entry
- Rate limiting: batch site API calls via `$batch` endpoint, cache site list between syncs (only re-scan sites modified since last sync via `lastModifiedDateTime`)

## Controller & Routes

### `SensitivityLabelController`

- `index()` — Paginated list of all sensitivity labels with affected partner count, protection type badges, scope indicators. Alert banner for uncovered partners count.
- `show($id)` — Label detail with affected partners list (showing `matched_via` and policy/site name from pivot), sub-labels if any, protection details.

### Routes

```
GET /sensitivity-labels                          -> SensitivityLabelController@index
GET /sensitivity-labels/{sensitivityLabel}       -> SensitivityLabelController@show
```

## Frontend

### Pages

- `resources/js/pages/sensitivity-labels/Index.vue` — Table with columns: name, protection type, scope, priority, affected partners count, state badge
- `resources/js/pages/sensitivity-labels/Show.vue` — Detail page with partner list, matched_via indicator, protection config summary

### Partner Detail Integration

- Add "Sensitivity Labels" section to `partners/Show.vue` (mirrors CA policies section)
- Shows labels impacting this partner with matched_via context
- Warning alert if partner has no label coverage

### Navigation

- Add "Sensitivity Labels" item to sidebar nav, grouped near Conditional Access

## Compliance Report Integration

- Add `no_sensitivity_label_coverage_count` to `ComplianceReportController`
- Factor into overall compliance score (same weight as CA policy coverage)
- CSV export includes sensitivity label count per partner

## Graph API Permissions

- `InformationProtection.Read.All` — Read sensitivity labels and policies
- `Sites.Read.All` — Read site sensitivity label assignments and sharing config

## RBAC

Read-only for all roles (viewer, operator, admin) — same as conditional access policies.
