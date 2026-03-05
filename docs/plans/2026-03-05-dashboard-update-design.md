# Dashboard Update Design

## Goal

Update the dashboard to serve both daily triage (operators) and executive overview (admins) by surfacing new features: stale guests, pending entitlement approvals, overdue access reviews, and partners needing attention.

## Layout: "Action Center"

Three rows: stats, triage, activity.

## Section 1: Top Stats Row

5 stat cards in a responsive grid (`lg:grid-cols-5`, `md:grid-cols-3`):

| Card | Query | Link | Conditional Color |
|------|-------|------|-------------------|
| Total Partners | `PartnerOrganization::count()` | `/partners` | - |
| Total Guests | `GuestUser::count()` | `/guests` | - |
| Stale Guests | `GuestUser where last_sign_in_at < 90d ago OR null` | `/reports` | Amber when > 0 |
| Pending Invitations | `GuestUser where status = pending_acceptance` | `/guests?status=pending_acceptance` | - |
| Overdue Reviews | `AccessReviewInstance where status in [pending, in_progress] AND due_at < now` | `/access-reviews` | Red when > 0 |

Drops: MFA Trust Enabled card (low actionability), Partners by Category chart.

## Section 2: Triage Section (Two Cards Side-by-Side)

### Left: Pending Approvals

- 5 most recent `AccessPackageAssignment` where `status = pending_approval`, ordered by `requested_at` asc
- Each row: target user email, access package name, time since request, Approve/Deny buttons
- Approve/deny use existing `EntitlementController` endpoints via `router.post()` with `preserveScroll`
- Empty state: "No pending approvals"

### Right: Partners Needing Attention

- 5 `PartnerOrganization` where `trust_score < 70`, ordered by `trust_score` asc
- Each row: partner name (linked to show page), trust score badge (red < 50, amber 50-69), stale guest count
- Uses `withCount` for stale guests (same 90-day logic)
- Empty state: "All partners in good standing"

## Section 3: Recent Activity

- Same design as current, trimmed from 20 to 10 entries
- Full-width card at the bottom

## Backend Changes (DashboardController)

- Remove: `partners_by_category`, `mfa_trust_enabled`, `mfa_trust_disabled`
- Add: `pending_approvals` (5 records with eager-loaded `accessPackage`)
- Add: `attention_partners` (5 records with stale guest count)
- Rename: `inactive_guests` to `stale_guests`
- Keep: `overdue_reviews` (already queried, now displayed)
- Change: `recentActivity` from 20 to 10

No new routes, models, or migrations.

## Frontend Changes (Dashboard.vue)

- Update props type: drop removed fields, add `pending_approvals` array and `attention_partners` array
- Stats row: 5 cards with conditional amber/red text classes
- Triage row: replaces Partners by Category and uses two new card sections
- Activity: receives 10 items instead of 20
- No new components, composables, or type files

## Testing

- Seed partners with varying trust scores, stale guests, pending assignments, overdue review instances
- Assert correct stat counts in Inertia response
- Assert `pending_approvals` max 5, ordered by `requested_at` asc
- Assert `attention_partners` only includes trust_score < 70
- Assert `recentActivity` max 10 entries
