# Compliance Reporting & Stale Guest Detection Design

Covers GitHub issues #9 (Compliance reporting dashboard) and #7 (Guest sign-in activity monitoring and stale account detection).

## Summary

A unified compliance reporting page at `/reports` that surfaces policy compliance metrics across partners and guest health metrics. Includes CSV export for audit documentation and enhancements to the existing guests list for sign-in activity visibility.

## Decisions

- **Unified page**: Issues #7 and #9 combined into one `/reports` page since stale guest detection is a compliance metric.
- **Approach**: Inertia controller + server-side aggregation (Approach A). Consistent with existing codebase patterns.
- **Thresholds**: Fixed 30/60/90 day buckets for stale guest detection. Not configurable.
- **Export**: CSV only. Streamed response, no extra packages.
- **Actions**: Read-only with links to existing detail pages. No bulk actions on the compliance page.

## Route & Controller

### Routes (in `routes/web.php`, protected group)

```
GET  /reports        -> ComplianceReportController@index
GET  /reports/export -> ComplianceReportController@export
```

Accessible to all authenticated roles (admin, operator, viewer) since it's read-only.

### ComplianceReportController

**`index()`** — Aggregates metrics from local DB (no Graph API calls), returns Inertia `reports/Index` page with props:

1. **Partner Policy Compliance**
   - Partners without MFA trust enabled (count + list)
   - Partners without device trust enabled (count + list)
   - Partners with overly permissive policies (both B2B inbound AND outbound enabled)
   - Partners with no conditional access policies targeting them
   - Overall compliance score: percentage of partners meeting baseline (MFA trust enabled + not overly permissive)
   - Average trust score across all partners

2. **Guest Account Health**
   - Stale guests by bucket: 30+ days, 60+ days, 90+ days since last sign-in
   - Never signed in (last_sign_in_at is null)
   - Pending invitations (not accepted)
   - Disabled accounts

**`export()`** — Streams CSV with two sections:
- Partner rows: Name, Domain, MFA Trust, Device Trust, B2B Inbound, B2B Outbound, Trust Score, CA Policy Count
- Guest rows: Email, Display Name, Partner, Last Sign-In, Days Inactive, Invitation Status, Account Enabled

## Frontend

### Page: `resources/js/pages/reports/Index.vue`

**Layout** (top to bottom):

1. **Summary Bar** — Three stat cards:
   - Overall compliance score (percentage, color-coded green/yellow/red)
   - Total partners with issues (count)
   - Total stale guests (90+ days)

2. **Partner Policy Compliance** — Card with table:
   - Columns: Partner name (link), MFA Trust, Device Trust, B2B Openness, CA Policies
   - Badge per cell (compliant/non-compliant)
   - Filter tabs: All Issues | No MFA | Overly Permissive | No CA Policies

3. **Guest Account Health** — Card with:
   - Stale guest breakdown (30/60/90 day buckets) as stat row
   - Table sorted by staleness (oldest first)
   - Columns: Guest email (link), Partner, Last Sign-In, Days Inactive, Status
   - Filter tabs: All Stale | 30+ Days | 60+ Days | 90+ Days | Never Signed In

4. **Export Button** — Top-right, downloads CSV

**Navigation**: Add "Reports" to sidebar with chart/clipboard icon.

**Components**: Uses existing shadcn-vue (Card, Table, Badge, Button, Tabs). No new libraries.

### Guests List Enhancement (Issue #7)

- Add sortable "Last Sign-In" column to `guests/Index.vue`
- Add "Days Inactive" badge: green <30d, yellow 30-60d, orange 60-90d, red >90d
- Update `GuestUserController@index` to support sorting by `last_sign_in_at`

## Testing

`tests/Feature/ComplianceReportTest.php`:
- Index page loads with correct metric structure
- Compliance score calculation is accurate
- Stale guest buckets computed correctly
- CSV export contains expected columns and data
- All authenticated roles can access the page

Update existing guest controller tests for new sort parameter.
