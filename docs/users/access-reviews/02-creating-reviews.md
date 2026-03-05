---
title: Creating Reviews
---

# Creating Access Reviews

Creating an access review is the first step in evaluating whether your [guest users](/docs/glossary/glossary) still need access to your tenant. A well-scoped review with clear parameters makes the reviewer's job easier and produces a more meaningful audit trail.

## Prerequisites

Before creating a review, ensure the following:

- Your account has the **Operator** or **Admin** [role](/docs/glossary/glossary). Viewers cannot create reviews.
- At least one [partner organization](/docs/glossary/glossary) exists in Partner365 with associated guest users. A review with no guests in scope will generate zero instances, which is not useful.
- Guest data is reasonably current. If you suspect guest information may be stale, trigger a sync from **Admin > Sync** before creating the review. This ensures you are working with the latest guest list from Microsoft Entra ID.

## Steps

Navigate to **Access Reviews** in the sidebar and click **Create Review**. Configure the following fields:

### Name

Choose a descriptive name that communicates the review's purpose and timeframe. Good examples include "Q1 2026 Vendor Guest Review," "Monthly Contoso Access Review," or "Post-Project Fabrikam Cleanup." A clear name helps reviewers understand context at a glance and makes it easier to find specific reviews in the history later.

### Review Type

Select the scope of the review:

- **Partner-scoped** — Evaluates all guests from a single partner organization. Use this when you want to focus on a specific collaboration relationship, or when different reviewers are responsible for different partners.
- **All guests** — Evaluates every guest across all partner organizations in a single review. Use this for comprehensive tenant-wide assessments, typically aligned with quarterly compliance cycles.

### Scope Partner

If you selected a partner-scoped review, choose which [partner organization](/docs/glossary/glossary) to review. Consider prioritizing partners based on risk factors: those with low [trust scores](/docs/glossary/glossary), a large number of guests, or access to sensitive resources should be reviewed first and more frequently.

### Recurrence

Define how often the review should repeat:

- **One-time** — A single review with no automatic follow-up. Appropriate for ad-hoc evaluations such as post-project cleanup or investigating a specific partner's access.
- **Weekly** — Generates a new review every week. Reserved for exceptional situations where guest turnover is very high.
- **Monthly** — A new review each month. Suitable for high-risk partners or environments with frequent guest changes.
- **Quarterly** — A new review every three months. This is the standard cadence for most compliance frameworks and a good default for the majority of partners.

### Due Date

Set the date by which all decisions must be submitted. After this date, the review expires and no further decisions can be recorded. Allow enough time for the reviewer to evaluate each guest thoughtfully — a review with 50 guests needs more time than one with 5. As a guideline, allow at least one week for partner-scoped reviews and two weeks for tenant-wide reviews.

### Remediation Action

Specify what happens to denied guests when remediations are applied. The standard action is removal from the tenant via the Microsoft Graph API. This revokes the guest's access to all resources in your tenant. Understand that removal is irreversible — if a denied guest needs access again later, they must be re-invited.

### Reviewer

Assign the person who will make approve/deny decisions for each guest. The reviewer should be someone familiar with the collaboration relationship — typically a project manager, team lead, or the person who originally invited the guests. The reviewer must have at least Operator-level access to Partner365.

## After Creation

Once you click **Create**, Partner365 generates review instances — one for each guest user in scope at that moment. The reviewer will see all instances on the review detail page, where they can work through decisions at their own pace until the due date.

For recurring reviews, new instances are created automatically at the start of each cycle based on the current guest population. Guests added after a cycle begins will be picked up in the next cycle. Guests removed before the next cycle are excluded.

> **Good to know:** Start with a partner-scoped review for your highest-risk partner — the one with the lowest trust score or the most guests. This lets you learn the review workflow on a manageable set of decisions before scaling to tenant-wide reviews. See [Best Practices](04-best-practices.md) for more guidance on review strategy.
