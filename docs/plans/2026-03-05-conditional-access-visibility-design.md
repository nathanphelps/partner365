# Conditional Access Policy Visibility — Design

GitHub Issue: #6

## Summary

Surface which Conditional Access (CA) policies affect external/guest users per partner. Read-only visibility with basic gap detection — no policy management.

## Data Model

### `conditional_access_policies` table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | Auto-increment |
| policy_id | string, unique | Graph API object ID |
| display_name | string | Policy name |
| state | string | enabled, disabled, enabledForReportingButNotEnforced |
| guest_or_external_user_types | string | Comma-separated types (b2bCollaborationGuest, b2bDirectConnectUser, etc.) |
| external_tenant_scope | string | "all" or "specific" |
| external_tenant_ids | JSON, nullable | Array of tenant IDs when scope is "specific" |
| target_applications | string | "all" or summary of targeted apps |
| grant_controls | JSON | e.g., ["mfa", "compliantDevice"] |
| session_controls | JSON | e.g., ["signInFrequency", "persistentBrowser"] |
| raw_policy_json | JSON | Full Graph API response |
| synced_at | timestamp | Last sync time |
| created_at / updated_at | timestamps | Standard Laravel timestamps |

### `conditional_access_policy_partner` pivot table

| Column | Type | Description |
|--------|------|-------------|
| conditional_access_policy_id | FK | Links to CA policy |
| partner_organization_id | FK | Links to partner |
| matched_user_type | string | Which guest/external user type matched |

### Gap Detection

Partners with zero rows in the pivot table are flagged as "uncovered" (no CA policies target their guests).

## Backend

### ConditionalAccessPolicyService

Depends on `MicrosoftGraphService`.

- `fetchPoliciesFromGraph()` — `GET /identity/conditionalAccess/policies`, filters to those with `includeGuestsOrExternalUsers` conditions
- `syncPolicies()` — Fetches from Graph, upserts to DB, rebuilds partner pivot mappings with granular tenant/user-type matching
- `getPoliciesForPartner(PartnerOrganization $partner)` — Query via pivot
- `getUncoveredPartners()` — Partners with no matching policies

### Sync Command

`sync:conditional-access-policies` — standalone Artisan command, scheduled every 15 minutes. Logs activity via `ActivityLogService`.

### ConditionalAccessPolicyController

- `index()` — Global list page with all guest-targeting CA policies + uncovered partner count
- `show($id)` — Single policy detail with list of affected partners

### Routes

- `GET /conditional-access` — index
- `GET /conditional-access/{conditionalAccessPolicy}` — show
- Partner detail page (`partners/{id}`) gets CA policies added to its existing props

### New Enum Values

- `ActivityAction::ConditionalAccessPoliciesSynced`

## Frontend

### Pages

**`conditional-access/Index.vue`** — Table with columns: name, state (badge), grant controls, affected partner count. Alert banner showing uncovered partner count.

**`conditional-access/Show.vue`** — Policy detail card: name, state, targeted user types, external tenant scope, grant controls, session controls, target applications. Table of affected partners with matched user type. Link to Entra admin center.

**`partners/Show.vue`** — New "Conditional Access" section. Compact table of policies applying to this partner. Warning alert if no policies apply. Link to full CA policy list.

### Types (`conditional-access.ts`)

```typescript
interface ConditionalAccessPolicy {
    id: number;
    policy_id: string;
    display_name: string;
    state: string;
    guest_or_external_user_types: string;
    grant_controls: string[];
    session_controls: string[];
    target_applications: string;
    partners_count?: number;
    partners?: PartnerOrganization[];
}
```

### Navigation

Sidebar item "Conditional Access" with Shield icon, after "Access Reviews". Visible to all roles.

## Testing

### ConditionalAccessPolicySyncTest

Mock Graph API, verify upsert correctness, pivot mapping with correct `matched_user_type`, non-guest policies ignored, stale policies removed on re-sync.

### ConditionalAccessPolicyControllerTest

Index returns policies as Inertia props, show returns policy with partners, partner show includes CA policies, uncovered partner count correct. All roles can view.

### ConditionalAccessPolicyServiceTest

Graph filtering logic, granular matching (all tenants vs specific, different user types), uncovered partner detection.

### Mock Data

Mix of policies: some targeting all guests, some targeting specific tenants, some not targeting external users — to exercise filtering and matching logic.
