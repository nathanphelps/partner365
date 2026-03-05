---
title: Assignments
---

# Entitlement Assignments

Assignments connect [guest users](/docs/glossary/01-glossary) to [access packages](/docs/users/entitlements/01-access-packages). Each assignment represents one guest's access to one package and tracks the full lifecycle from initial request through approval, active access, and eventual expiry or revocation. All assignment transitions are recorded in the [activity log](/docs/glossary/01-glossary) for audit purposes.

## Assignment Lifecycle

Every assignment moves through a defined set of stages. Understanding these stages helps you manage access effectively and respond to requests promptly.

1. **Requested** — A user or admin initiates an assignment request. The request is recorded with the identity of the requester, the target guest, and an optional justification. This is the starting state for all assignments.

2. **Pending Approval** — If the access package requires approval, the assignment enters this stage and waits for an Operator or Admin to review it. The request appears on the Dashboard under "Pending Approvals" and on the specific entitlement's detail page. No access is granted during this stage.

3. **Approved** — An Operator or Admin has approved the request. Partner365 calls the Microsoft Graph API to add the guest to every group and SharePoint site in the package. Once all resources are provisioned, the assignment becomes active.

4. **Active** — The guest has access to all resources in the package. The assignment remains in this state until the configured duration elapses, or an admin manually revokes it. The expiry date is calculated from the approval date plus the package's duration in days.

5. **Expired** — The package duration has elapsed. The background queue worker automatically removes the guest from all groups and SharePoint sites in the package. The assignment is retained in the system for audit trail purposes but no longer grants any access.

6. **Denied** — An Operator or Admin rejected the request during the Pending Approval stage. No access was ever granted. The denial reason (if provided) is stored with the assignment record.

7. **Revoked** — An Admin manually removed access before the natural expiry date. This immediately triggers removal of the guest from all resources in the package. Use this when a collaboration ends early or when a guest no longer needs access.

> **Good to know:** Set package durations to match your collaboration timeline. A 90-day project should have a 90-day package duration — this ensures access is automatically cleaned up when the project ends, without anyone needing to remember to revoke it manually.

## Managing Assignments

On the access package detail page, the **Assignments** table shows all current and historical assignments. Each row includes the guest's name, their partner organization, the current status, the request date, and the expiry date (if applicable).

- **Pending assignments** display inline **Approve** and **Deny** buttons. Clicking either prompts you for an optional justification note before confirming the action.
- **Active assignments** display a **Revoke** button. Revoking an assignment immediately removes the guest from all resources in the package. This action cannot be undone — to restore access, you would need to create a new assignment.
- **Expired, Denied, and Revoked assignments** are displayed for audit purposes. They cannot be modified but provide a complete history of who had access and when.

Every approval, denial, and revocation is logged in the [activity log](/docs/glossary/01-glossary) with the acting user's identity, a timestamp, and the justification note if one was provided.

## Approval Workflow

When a package requires approval and a new assignment request comes in, it appears in two places: the **Dashboard** under the "Pending Approvals" section, and on the specific entitlement's detail page in the assignments table.

Before approving a request, review the following:

- **Who is requesting** — Which user or admin submitted the request, and is this expected?
- **Which guest** — Confirm the guest user is the correct person and belongs to the right partner organization.
- **What resources the package grants** — Review the groups and SharePoint sites in the package to understand what access you are approving.
- **Whether the request is justified** — Read the justification note (if provided) and determine whether the access is appropriate for the guest's role.

When you approve or deny, add a justification note explaining your decision. This note is stored with the assignment and visible in the activity log, which helps during future audits and access reviews.

## Requesting Access

Operators and Admins can request an assignment on behalf of a [guest user](/docs/glossary/01-glossary). On the entitlement detail page, click **Request Assignment** to begin.

1. **Select the guest** — Search for a guest user from the partner organization associated with the package. Only guests from the correct partner are shown.
2. **Add a justification** (optional) — Provide context for why this guest needs the access. This is especially helpful when the package requires approval, as the reviewer will see this note.
3. **Submit the request** — If the package requires approval, the request enters the approval queue and the assignment status becomes Pending Approval. If no approval is required, the assignment is immediately approved and access is provisioned.

> **Good to know:** Guests cannot request access packages themselves through Partner365. An Operator or Admin must always initiate the assignment. This ensures that all access requests go through a known internal user who can vouch for the need.

## Related Pages

- [Access Packages](/docs/users/entitlements/01-access-packages) — Creating and managing packages
- [Best Practices](/docs/users/entitlements/03-best-practices) — Design guidance for durations and approvals
- [Troubleshooting](/docs/users/entitlements/04-troubleshooting) — Common issues and solutions
