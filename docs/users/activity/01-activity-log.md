---
title: Activity Log
---

# Activity Log

The Activity page provides a chronological audit trail of every significant action performed in Partner365.
It serves as your primary record for understanding who changed what, when, and why — essential for security investigations, compliance audits, and day-to-day operational awareness.

## What Gets Logged

Every write operation in Partner365 is recorded automatically.
You do not need to enable logging — it is always active.
The following action types are tracked, each corresponding to an [ActivityAction](/docs/glossary/01-glossary) enum value and displayed with a distinctive color-coded badge:

- **Partner created / updated / deleted** — Any change to a partner organization's record, including initial creation from a template or manual setup, modifications to metadata, and removal.
- **Guest invited / updated / removed** — Guest lifecycle events from initial invitation through profile updates to eventual removal from the directory.
- **Cross-tenant policies modified** — Changes to inbound or outbound B2B collaboration settings, B2B direct connect settings, or trust settings for any partner.
- **Access review created / decisions made / remediations applied** — The full lifecycle of an access review, from scheduling through individual approve/deny decisions to automated remediation actions.
- **Entitlement assignments approved / denied / revoked** — Decisions on entitlement requests and any subsequent revocations.
- **Sync operations completed or failed** — Background sync results, including record counts and any errors encountered during partner or guest reconciliation.
- **User management changes** — Role changes, user approvals, and user removals within Partner365 itself.

Each action type appears with a distinctive badge in the log, making it easy to scan visually for specific categories of activity.

## Reading Log Entries

Each entry in the activity log displays the following information:

- **Action type** — A color-coded badge identifying the category of action.
  Green badges indicate creates, yellow badges indicate updates, and red badges indicate deletes.
  The badge color makes it easy to scan the log visually for specific types of activity.
- **Description** — A human-readable summary of what happened, such as "Updated cross-tenant policy for Contoso Ltd" or "Invited guest user jane@partner.com."
- **User** — The name of the person who performed the action.
  For automated operations like background sync, this shows "System."
- **Timestamp** — The exact date and time the action occurred, displayed in your local timezone.
- **Changes** — For modification actions, the before and after values are shown so you can see exactly what changed.
  This is particularly useful for policy modifications where you need to understand what was tightened or loosened.

Click any entry to expand it and view the full details, including the complete before/after state for modifications and any additional context recorded with the action.

## Filtering Strategies

The activity log supports several filter types that can be combined for precise queries:

- **Action Type** — Select one or more action categories to focus on specific kinds of changes.
  Use this to see all policy modifications across every partner, or to review all guest removals.
  This is the most common starting filter.
- **User** — Filter by who performed the action.
  Useful for auditing a specific person's activity over a given period, or for verifying that only authorized users made sensitive changes.
- **Date Range** — Narrow the log to a specific time window.
  Combine with other filters for targeted investigations, such as "show me all guest removals this week" or "show me all policy changes in January."
- **Search** — Full-text search works across descriptions and detail fields.
  Search for a specific partner name, guest email address, or policy setting to find all related activity regardless of action type.

Combining filters is where the activity log becomes most powerful.
For example, filtering by Action Type "Policy Modified" and Date Range "last 7 days" quickly answers "what policy changes happened this week?"

## Using Activity Data for Investigations

When investigating a security concern or responding to an incident, the activity log is your starting point:

1. **Search for the affected resource** — Enter the partner name, guest email, or other identifier in the search field to find all related activity.
2. **Review the timeline** — Look at the sequence of actions taken on that resource.
   When was it created? Who modified it? What changed and when?
3. **Check authorization** — Verify that the users who made changes had appropriate roles and that the changes were expected.
   Unexpected modifications by unfamiliar users warrant further investigation.
4. **Export for documentation** — Use the export function to save the filtered results as a file.
   This creates a permanent record that can be attached to incident reports or shared with security teams.

The activity log records all write operations without exception.
If something changed in Partner365, there is a corresponding log entry.
This makes it a reliable single source of truth for understanding the history of any resource.

> **Good to know:** The activity log is essential for compliance audits.
> Export filtered logs regularly and archive them alongside your [compliance reports](/docs/users/reports/01-compliance-reports).
> Together, they provide a complete picture: the compliance report shows your current posture, and the activity log shows the actions that shaped it.

## Retention

Activity log entries are retained indefinitely within Partner365.
For long-term archival or integration with external SIEM systems, use the export function to extract log data on a regular schedule.
