---
title: Compliance Reports
---

# Compliance Reports

The Reports page provides a structured view of your organization's external collaboration posture.
Use it to assess whether your [partner](/docs/glossary/glossary) configurations, [guest](/docs/glossary/glossary) lifecycle management, and [access reviews](/docs/glossary/glossary) align with your security standards — and to identify areas that need attention.

## What Is Compliance in This Context?

Compliance in Partner365 refers to how well your external collaboration setup adheres to your organization's policies and security best practices.
The compliance report measures three dimensions:

- **Partner configuration** — Are your [cross-tenant access policies](/docs/glossary/glossary) properly configured?
  Do they enforce MFA trust, restrict overly permissive access, and have conditional access policy coverage?
- **Guest lifecycle management** — Are guest accounts actively monitored?
  Are stale or inactive guests being identified and addressed before they become a security risk?
- **Access review completion** — Are scheduled access reviews being completed on time, with decisions acted upon?

The report brings these dimensions together into a single view so you can quickly assess your overall posture and drill into specific problem areas.

## Summary Cards

At the top of the report page, three summary cards provide an at-a-glance overview:

- **Overall Compliance Score** — A percentage reflecting how well your external collaboration posture aligns with best practices.
  This score factors in partner policy configuration, guest activity status, and access review completion rates.
  A score of 100% means all partners are properly configured, all guests are actively managed, and all access reviews are complete.
- **Partners with Issues** — The count of partner organizations that have one or more configuration concerns flagged, such as missing MFA trust or overly permissive policies.
- **Stale Guests** — The count of guest accounts that have not signed in within your defined activity threshold, indicating they may no longer need access.

## Report Tabs

The report is organized into two tabs, each focused on a different dimension of compliance.

### Partner Compliance

This tab lists each partner organization alongside its trust score and any flagged issues.
Common flags include:

- **No MFA trust** — The partner's cross-tenant policy does not accept MFA claims from the partner's tenant, meaning guests from this partner may bypass your MFA requirements.
- **Overly permissive policies** — The partner has wide-open inbound or outbound access that could be tightened to follow least-privilege principles.
- **No conditional access policy coverage** — No conditional access policy specifically addresses access from this partner's users.

Use the filter controls to narrow the list by issue type.
This is particularly useful when you want to focus on a single category of concern — for example, finding all partners without MFA trust configured.

### Guest Health

This tab breaks down guest accounts by their recent sign-in activity:

- **Active** — Signed in within the last 30 days. These guests are actively using their access.
- **Inactive 30-59 days** — Low risk, but worth monitoring. These guests may still need access but have not used it recently.
- **Inactive 60-89 days** — Moderate risk. Consider reaching out to confirm whether access is still needed.
- **Inactive 90+ days** — High risk. These accounts are candidates for removal or access review.
- **Never signed in** — The guest was invited but has never accepted or used the invitation.
  This may indicate a stale invitation or a user who no longer needs access.

Each bucket indicates an increasing level of risk, helping you prioritize which guest accounts to review first.

## Exporting

Click **Export** to download the currently displayed report data as a CSV file.
The export respects your active filters, so you can export a focused subset of the data.
Exported reports are useful for:

- Sharing with auditors as evidence of ongoing access monitoring
- Importing into GRC (governance, risk, and compliance) tools for centralized tracking
- Creating executive summaries or trend analysis over time

> **Good to know:** Export filtered reports regularly and archive them.
> Having historical snapshots makes it much easier to demonstrate compliance trends during audit periods.

## Filters

Narrow the report data using the available filter controls:

- **Date range** — Focus on a specific reporting period.
- **Specific partners** — Limit the report to one or more partner organizations.
- **Issue types** — Show only partners with a particular category of concern (no MFA trust, overly permissive, no CA coverage).

Combine filters to answer targeted questions, such as "Which partners added in the last quarter have no MFA trust configured?"
Use filters to focus your remediation efforts on the highest-priority items first.

See also: [Using Reports](/docs/reports/using-reports) for guidance on incorporating reports into your regular workflow.
