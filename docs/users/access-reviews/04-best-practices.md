---
title: Best Practices
---

# Access Review Best Practices

Running access reviews is straightforward mechanically — the value comes from doing them consistently, scoping them thoughtfully, and acting on the results promptly. This page collects recommendations drawn from common compliance frameworks and real-world access governance patterns.

## Review Frequency

Quarterly reviews are a strong default for most organizations. This cadence aligns with SOC 2 and ISO 27001 expectations, fits naturally into business planning cycles, and is frequent enough to catch stale accounts before they become a significant risk.

However, not all partners warrant the same frequency:

- **Monthly** — Use for high-risk partners: those with low [trust scores](/docs/glossary/01-glossary), a large number of [guest users](/docs/glossary/01-glossary), or access to sensitive resources such as financial data or intellectual property. Monthly reviews ensure that changes in the collaboration relationship are reflected quickly in access decisions.
- **Quarterly** — The standard cadence for partners with moderate risk profiles and stable guest populations. Most partners fall into this category.
- **One-time** — Use for situational reviews: post-project cleanup, investigating a specific partner after a security incident, or evaluating guests before a compliance audit.

Configure recurring reviews rather than relying on manual creation. Recurring reviews automatically generate new instances on schedule, ensuring that reviews happen consistently even when the person who normally creates them is unavailable. A missed quarterly review creates a gap in your audit trail that is difficult to explain to auditors.

> **Good to know:** You can have multiple recurring reviews running simultaneously — for example, a monthly review for your highest-risk partner and a quarterly review covering all other guests. Partner365 handles overlapping schedules without conflict.

## Scope Strategy

Start with partner-scoped reviews before moving to tenant-wide reviews. Partner-scoped reviews produce manageable batches of decisions (typically 5-30 guests per partner) and let you assign reviewers who have direct knowledge of each collaboration relationship. A project manager who works with Contoso daily is better positioned to evaluate Contoso's guests than someone reviewing 200 guests across 15 partners.

Once your team is comfortable with the review process, consider adding a tenant-wide review on a quarterly basis for comprehensive coverage. This catches any guests that might slip through partner-scoped reviews — for example, guests from partners that were recently added and don't yet have a dedicated review schedule.

For organizations with many partners, a practical pattern is:

1. **Monthly** partner-scoped reviews for your top 3-5 highest-risk partners.
2. **Quarterly** tenant-wide review covering all guests, providing a safety net.

This layered approach ensures high-risk partners get frequent attention while no guest goes unreviewed for more than 90 days.

## Decision Criteria

Consistent decision criteria across reviewers produce a more defensible audit trail. Establish clear guidelines for your team:

**Approve** when all of the following are true:
- The guest has signed in within the last 90 days, indicating active use.
- The collaboration with the [partner organization](/docs/glossary/01-glossary) is ongoing.
- The guest's access level is appropriate for their current role in the collaboration.

**Deny** when any of the following are true:
- The guest has not signed in for 90 or more days without a known reason (such as a seasonal project).
- The project or engagement the guest was invited for has ended.
- The partner relationship has changed materially (contract expired, vendor replaced, acquisition).
- The guest's access exceeds what is needed for their current role.

When you are unsure, check the guest's detail page to review their group memberships, application assignments, and SharePoint site access. Understanding what a guest can actually reach helps inform whether their access level is appropriate or excessive.

> **Good to know:** Document your organization's decision criteria in an internal policy document and share it with all reviewers. Consistent criteria reduce reviewer uncertainty and produce audit trails that tell a coherent story.

## Handling Expired Reviews

If a review expires before all decisions are submitted, take the following steps:

1. **Create a new review** targeting the same scope. Expired reviews cannot be resumed, so a fresh review is the only path forward.
2. **Investigate the cause.** Why were decisions not completed on time? Common reasons include: the reviewer was unaware of the pending review, the due date was too aggressive for the number of guests in scope, or the reviewer left the organization.
3. **Adjust for next time.** If the due date was unrealistic, extend it on the new review. If the reviewer was unresponsive, assign a different reviewer. If awareness was the issue, consider setting up email reminders or mentioning pending reviews in team standups.

Expired reviews are not compliance failures by themselves, but a pattern of expirations signals a process problem that needs to be addressed. Auditors will notice repeated expirations and may ask questions about the effectiveness of your review program.

## Remediation Timing

Apply remediations promptly after a review reaches completion. Every day between completing a review and applying remediations is a day that denied guests retain access they should not have. This delay undermines the purpose of the review and creates an awkward gap in your audit trail — you decided the guest should not have access, but they still do.

Aim to apply remediations within 24-48 hours of review completion. If your organization requires an approval step before guest removal, build that into the review timeline by setting due dates earlier to allow time for the approval-then-remediation sequence.

> **Good to know:** After applying remediations, spot-check a few removed guests in the [activity log](/docs/glossary/01-glossary) to confirm the removals completed successfully. If any failed, address them individually from the guest detail page. See [Troubleshooting](05-troubleshooting.md) for common remediation failure causes.
