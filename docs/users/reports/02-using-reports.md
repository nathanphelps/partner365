---
title: Using Reports
---

# Using Reports

The [compliance report](/docs/reports/compliance-reports) is most valuable when used as part of a regular workflow rather than only when an audit is imminent.
This page covers practical strategies for getting the most out of your report data.

## Regular Monitoring

Run the compliance report at least monthly to track trends in your external collaboration posture.
Key indicators to watch:

- **Compliance score trajectory** — Is the overall score improving, stable, or declining?
  A declining score suggests new partners are being added without proper configuration, or existing configurations are drifting.
- **Stale guest count** — An increasing count of inactive [guests](/docs/glossary/glossary) indicates that lifecycle management is not keeping pace with invitation volume.
  This is one of the most common audit findings.
- **Partner issue count** — Compare month-over-month to identify whether new issues are appearing faster than existing ones are being resolved.

Establishing a baseline during your first report run gives you a reference point.
Subsequent runs become meaningful when you can compare against that baseline and identify deterioration early — before it becomes an audit finding or a security incident.

> **Good to know:** Consider scheduling a monthly review meeting with your security team where you walk through the latest compliance report.
> Having a recurring cadence makes it harder for issues to slip through unnoticed.

## Preparing for Audits

Export compliance reports well before audit periods begin, not the day before.
The report provides concrete evidence of:

- **Partner policy configuration** — Demonstrates that [cross-tenant access policies](/docs/glossary/glossary) are deliberately configured rather than left at permissive defaults.
- **Guest lifecycle management** — Shows that inactive guests are identified and addressed, not left with indefinite access.
- **Access review completion rates** — Proves that periodic reviews are happening and decisions are being acted upon.

Pair compliance report exports with [activity log](/docs/activity/activity-log) exports for a complete audit trail.
The compliance report shows the current state, while the activity log shows how you got there — who made changes, when, and why.

Most compliance frameworks that cover external access — including SOC 2, ISO 27001, and NIST 800-53 — require evidence of periodic access reviews and controls over external collaboration.
The compliance report maps directly to these requirements.

> **Good to know:** Create a dedicated folder for each audit period containing the compliance report CSV, activity log export, and any remediation notes.
> This makes it straightforward to hand evidence to auditors without scrambling.

## Identifying Issues

Use the partner compliance tab to find [partners](/docs/glossary/glossary) that need attention.
Practical filtering strategies:

- **Filter by "no MFA trust"** — These partners have guests who can sign in without your organization's MFA requirements being enforced.
  This is often the highest-priority issue because it represents a direct authentication gap.
- **Filter by "overly permissive"** — These partners have wide-open cross-tenant access policies that should be tightened.
  Review whether they genuinely need broad access or whether a more restrictive configuration would suffice.
- **Filter by "no CA coverage"** — These partners are not covered by any conditional access policy, meaning access from their users is ungoverned.

When prioritizing remediation, address partners that have both a low trust score and a large number of guest accounts first.
These represent the highest combined risk — weak policy configuration affecting the most users.

The guest health tab helps identify accounts that should be reviewed for removal.
Start with the "inactive 90+ days" and "never signed in" buckets, as these represent the clearest candidates for cleanup.

## Sharing with Stakeholders

The CSV export is designed to be shared beyond the immediate Partner365 admin team.
Consider distributing reports to:

- **Security teams** — For incorporation into broader security posture dashboards and risk assessments.
- **Compliance officers** — As evidence of ongoing external access governance.
- **Management** — As part of regular security status updates.

When creating a monthly summary for stakeholders, highlight:

- Overall compliance score and its trend direction (improving, stable, or declining).
- Number of issues resolved since the last report, demonstrating active management.
- Any new issues that have been identified and require attention or resources.
- Upcoming [access review](/docs/glossary/glossary) deadlines that may need stakeholder involvement.
- Guest account cleanup statistics showing lifecycle management is active.

A consistent reporting cadence builds confidence with stakeholders that external collaboration is being actively governed rather than left unmanaged.
