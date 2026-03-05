---
title: Overview
---

# Access Reviews

Access reviews help you periodically verify that [guest users](/docs/glossary/01-glossary) still need access to your tenant. By systematically evaluating each guest's continued need for access, you maintain a clean, well-governed collaboration environment and build a defensible audit trail of access decisions.

## Why Access Reviews Matter

Regulatory frameworks such as SOC 2, ISO 27001, and NIST 800-53 often mandate periodic reviews of external access. Even if your organization is not pursuing formal certification, regular access reviews are a security best practice. Over time, guest accounts accumulate through project collaborations, vendor engagements, and ad-hoc invitations. Without periodic review, you end up with [permission creep](/docs/glossary/01-glossary) — guests who retain access long after their need for it has ended. Each stale guest account represents an external identity with a path into your tenant, and that risk compounds as the number of unreviewed guests grows.

Access reviews give you a structured process to evaluate every guest, document your decisions, and act on denials — all in one workflow.

## How It Works

The access review lifecycle follows four stages:

1. **Create a review** — An operator or admin defines the review scope (which guests to evaluate), assigns a reviewer, sets a due date, and chooses a recurrence schedule. The review targets [guest users](/docs/glossary/01-glossary) specifically — internal users are not included because they are managed through other identity governance processes.
2. **Instances are generated** — Partner365 creates one review instance for each guest in scope. Each instance represents a single access decision that needs to be made. Instance data includes the guest's name, email, associated [partner organization](/docs/glossary/01-glossary), and last sign-in date to help inform the decision.
3. **Reviewer makes decisions** — The assigned reviewer works through each instance, approving or denying continued access. Justification notes can be added to each decision for audit purposes. The review detail page tracks overall progress with a compliance percentage indicator.
4. **Remediations are applied** — Once all decisions are finalized, denied guests can be removed from the tenant via the Microsoft Graph API. This step is explicit and requires confirmation, since guest removal is irreversible.

## Review Types

Partner365 supports two review scopes:

- **Partner-scoped** — Targets all guests associated with a specific [partner organization](/docs/glossary/01-glossary). This is useful when you want to evaluate access for a single vendor, customer, or collaborator. Different reviewers can handle different partners based on their knowledge of each relationship.
- **Tenant-wide** — Targets all guests across every partner organization. This provides comprehensive coverage in a single review and is well-suited for quarterly compliance cycles where you need to demonstrate that every external identity was evaluated.

## Review Status

Each review moves through one of three statuses:

- **Active** — The review is in progress. Instances have been generated and the reviewer is making decisions. The review remains active until all instances have a decision or the due date passes.
- **Completed** — All instances have received a decision (approve or deny). The review is ready for remediation if any guests were denied. Completed reviews are retained indefinitely for audit purposes.
- **Expired** — The review passed its due date before all decisions were submitted. Some instances may have decisions while others remain undecided. Expired reviews cannot be resumed — if decisions are still needed, create a new review targeting the same scope. Investigate why the review expired: was the due date too aggressive, or was the reviewer unaware of the pending work?

> **Good to know:** Expired reviews still appear in your review history and any decisions that were made before expiration are preserved. They simply cannot accept new decisions.

## Recurrence

Reviews can be configured as one-time or recurring. One-time reviews are useful for ad-hoc evaluations — for example, reviewing a partner's guests after a project ends. Recurring reviews automatically create new review instances on a set schedule: weekly, monthly, or quarterly. This ensures continuous compliance without relying on someone to remember to create reviews manually.

When a recurring review's cycle completes, Partner365 automatically generates the next review with fresh instances based on the current guest population. This means newly invited guests are picked up in the next cycle, and guests removed since the last cycle are excluded.

> **Good to know:** Quarterly recurrence aligns well with most compliance frameworks. Reserve monthly recurrence for high-risk partners where guest turnover is frequent or access is particularly sensitive.
