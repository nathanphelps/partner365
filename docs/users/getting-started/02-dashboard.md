---
title: Dashboard
---

# Dashboard

The dashboard is your central hub showing key metrics and items that need attention. It is designed to give you a quick read on the health of your external collaboration environment so you can spot issues early and act on them before they become problems.

## Stats Overview

At the top of the dashboard you will see summary cards showing high-level numbers. Each card is a signal — here is what they mean and what to watch for:

- **Total Partners** — The number of [partner organizations](/docs/glossary/glossary) configured in Partner365. This count should align with your organization's known external relationships. If it seems unexpectedly high, you may have legacy or test partners that should be reviewed and cleaned up.

- **Total Guests** — The total number of [guest users](/docs/glossary/glossary) in your tenant. A steadily growing number without corresponding business justification can indicate that guests are being invited but never removed. Compare this against the number of active partners to see if the ratio seems reasonable.

- **Pending Invitations** — Guest invitations that have been sent but not yet accepted. A small number of pending invitations is normal, but a large or growing backlog may indicate that invitations are being sent to incorrect email addresses, or that invited users are unaware they have been invited. Follow up with the inviting party if invitations remain pending for more than a few days.

- **Stale Guests** — Guests who have not signed in recently, based on the staleness threshold configured by your admin. A high number of stale guests may indicate that [access reviews](/docs/access-reviews/overview) have not been run recently, or that guests were granted one-time access and never cleaned up. These accounts represent unnecessary attack surface.

- **Overdue Reviews** — [Access reviews](/docs/access-reviews/overview) that have passed their due date without being completed. Overdue reviews are a compliance concern — they mean that guest access has not been validated on schedule. If you see overdue reviews, prioritize completing them or coordinate with the assigned reviewers.

## Action Items

Below the stats cards, the dashboard surfaces specific items that need your attention, organized by urgency.

### Pending Approvals

If you are an operator or admin, you will see entitlement access requests waiting for your approval. These requests are triggered when an external user requests access to an [access package](/docs/glossary/glossary) through the entitlement management system.

Before approving a request, consider:
- **Who is requesting** — Is the requestor from a known partner organization with an established trust relationship?
- **What resources are involved** — Does the access package grant access to sensitive data or broadly scoped SharePoint sites?
- **Is it justified** — Does the request include a business justification, and does that justification make sense for the requestor's role?

Click on any request to review its details and approve or deny it. Denied requests should include a reason so the requestor understands why.

### Partners Needing Attention

Partners appear in this section when their [trust score](/docs/concepts/trust-score) is low or when they have a high number of stale guest accounts. A low trust score typically indicates policy gaps — for example, a partner without inbound access restrictions, or one that has not been reviewed in a long time. A high stale guest count points to lifecycle management issues: guests were invited for a project that ended, but their accounts were never cleaned up.

Click on a partner to review their details, adjust their policies, or initiate an access review for their guest users.

### Recent Activity

A feed of the most recent actions taken in the system, including partner additions, guest invitations, policy changes, access review completions, and entitlement approvals. This serves as a quick monitoring feed so you can stay aware of what is happening without navigating to the full [Activity Log](/docs/activity/activity-log).

Each entry shows the action type, the user who performed it, and when it occurred. If something looks unexpected — such as a policy change you did not authorize or a bulk guest invitation you were not expecting — click through to the activity log for full details.

> **Good to know:** The dashboard is a good place to check daily. If you see overdue reviews or a spike in stale guests, investigate promptly. Catching issues early keeps your external collaboration environment secure and compliant.
