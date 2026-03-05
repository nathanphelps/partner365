---
title: Reviewing Decisions
---

# Reviewing Decisions

Once an access review has been created and instances generated, the assigned reviewer works through each [guest user](/docs/glossary/glossary) decision. This page explains what the review interface looks like, how to make well-informed decisions, and how to apply remediations once the review is complete.

## The Review Interface

Open a review from the **Access Reviews** list to reach its detail page. At the top, you will see the review name, status, due date, and a **compliance percentage** that updates in real time as decisions are recorded. This percentage reflects overall progress — when it reaches 100%, every instance has a decision.

Below the summary, a table lists each guest instance with the following columns:

- **Guest name and email** — The display name and email address of the guest account in your tenant.
- **Partner** — The [partner organization](/docs/glossary/glossary) the guest is associated with.
- **Last sign-in date** — When the guest most recently authenticated to your tenant. This is one of the strongest signals for whether the guest is still active.
- **Decision** — A dropdown to approve or deny continued access, along with an optional justification field.

You can work through instances in any order. Decisions are saved immediately — you do not need to complete all decisions in a single session. Return to the review detail page at any time before the due date to continue.

## Making Decisions

For each guest, evaluate whether continued access is warranted. Consider these factors:

### Last Sign-In Activity

Recent sign-in activity is the clearest indicator that a guest is actively using their access. A guest who signed in within the last 30 days is almost certainly still collaborating. A guest who has not signed in for 90 or more days is a strong candidate for denial — they may have moved on from the project or switched to a different account. A guest who has **never** signed in likely never accepted the invitation or never needed the access in the first place.

### Partner Relationship Status

Is the [partner organization](/docs/glossary/glossary) still actively collaborating with your team? If a project has ended, a vendor contract has expired, or a partner relationship has changed, the guests from that partner may no longer need access. Check the partner detail page for context on the relationship's current state and [trust score](/docs/glossary/glossary).

### Access Scope

If you are unsure what a specific guest can access, navigate to their guest detail page from the review interface. Review their group memberships, application assignments, and any SharePoint site access. A guest with broad access who is no longer actively collaborating represents more risk than one with narrowly scoped permissions.

### Decision Guidelines

- **Approve** if the guest signed in recently, the collaboration is ongoing, and their access level is appropriate for their current role. Approving means the guest's access continues unchanged.
- **Deny** if the guest has not signed in for an extended period, the project or engagement has ended, the partner relationship has changed, or the guest's access exceeds what is needed. Denying flags the guest for remediation (removal from the tenant).

> **Good to know:** When in doubt, err on the side of denial for guests who have not signed in for 90 or more days. If they turn out to still need access, they can be re-invited. Stale access that goes unrevoked is a bigger risk than the minor inconvenience of re-invitation.

## Adding Justifications

Each decision includes an optional justification field. While justifications are optional for approvals, you should **always** add a justification for denials. Good justification notes explain the reasoning behind the decision and create a meaningful audit trail. Examples:

- "Project ended December 2025, access no longer needed."
- "Guest has not signed in since June 2025. Contacted partner — confirmed this person left the organization."
- "Vendor contract expired. Denying pending contract renewal discussion."
- "Access level exceeds current need. Denying; will re-invite with reduced scope if needed."

Justifications are stored permanently with the review record and are visible to anyone who views the review history, including auditors. Thoughtful notes save time when questions arise months later about why a particular decision was made.

## Applying Remediations

After all decisions in a review are submitted (compliance reaches 100%), the **Apply Remediations** button becomes available. Clicking this button triggers the removal of all denied guests from your tenant via the Microsoft Graph API.

Before applying, take these precautions:

1. **Review the denial list one final time.** The review detail page shows a summary of all denied guests. Scan through it to confirm every denial is intentional.
2. **Understand that this action is irreversible.** Once a guest is removed, their access to all tenant resources is revoked immediately. If they need access again in the future, they must be re-invited through the standard [guest invitation](/docs/glossary/glossary) process.
3. **Apply promptly.** Delaying remediation after completing a review means denied guests retain access longer than intended, undermining the purpose of the review. See [Best Practices](04-best-practices.md) for more on remediation timing.

> **Good to know:** If remediation fails for individual guests (due to Graph API errors or permission issues), check the [activity log](/docs/glossary/glossary) for detailed error messages. You can remove failed guests manually from their detail page. See [Troubleshooting](05-troubleshooting.md) for common remediation issues.

## Review History

Completed reviews are retained indefinitely in Partner365 for audit purposes. From the Access Reviews list, you can view any past review and see:

- The full list of instances with each decision (approve or deny).
- Who made each decision and when.
- Justification notes for each decision.
- Whether remediations were applied, and the date they were executed.

This history provides the documentation trail that auditors and compliance teams need to verify that external access is being governed appropriately. No review data is ever automatically deleted — your complete access review history is always available.
