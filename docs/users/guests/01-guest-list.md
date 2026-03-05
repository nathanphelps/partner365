---
title: Guest List
---

# Guest List

The Guests page is your central view of all [B2B guest users](/docs/glossary/glossary) in your Microsoft 365 tenant. It provides filtering, sorting, and bulk actions to help you manage external collaborators across all partner organizations.

## Guest Information

Each row in the guest list displays the following columns:

- **Display Name** — The guest's name as it appears in your directory. This value is pulled from the guest's home tenant profile at the time of invitation and may differ from what you entered if the guest has updated their profile since accepting.
- **Email** — The guest's external email address, which is their primary identity in their home organization. This is the address that received the original invitation and the one they use to authenticate.
- **Partner** — The [partner organization](/docs/glossary/glossary) this guest is associated with. Click the partner name to navigate directly to that partner's detail page, where you can see cross-tenant policies and all other guests from the same organization.
- **Invitation Status** — The current state of the guest's invitation. **Pending** means the guest has not yet redeemed the invitation link. **Accepted** means the guest has an active account and can access shared resources. **Failed** indicates an error occurred during the invitation process — check the guest detail page for specifics.
- **Last Sign-In** — The most recent time the guest authenticated to your tenant. A blank value means the guest has never signed in, which is expected for pending invitations but is a concern for accepted guests. An accepted guest who has never signed in may indicate a forgotten invitation or an account that was never actually used.
- **Created Date** — The date and time the invitation was originally sent. Use this alongside Last Sign-In to understand how long a guest has had access and whether they are actively using it.

## Filtering

The guest list provides several filters to help you find specific guests or identify patterns:

- **Search** — Type a name or email address to instantly filter the list. Useful when you need to look up a specific person or check whether someone has already been invited.
- **Partner** — Select a partner organization from the dropdown to focus on guests from a single company. This is helpful during partner access reviews or when offboarding an entire collaboration relationship.
- **Status** — Filter by invitation status to find guests in a specific state. Use this to locate pending invitations that need follow-up or failed invitations that require troubleshooting.
- **Stale** — Toggle this filter to show only guests who have not signed in within the configured staleness threshold. Use this to identify guests who may no longer need access — these are prime candidates for an access review or removal. A guest who has been inactive for an extended period likely does not need continued access to your tenant resources.

> **Good to know:** The stale guest filter is one of the most useful tools for maintaining security hygiene. Check it regularly or set up [access reviews](/docs/access-reviews/overview) to automate the process. Catching inactive guests early reduces your external attack surface.

## Bulk Actions

Operators and admins can select multiple guests using the checkboxes on each row and then perform actions on the entire selection at once.

- **Resend Invitations** — Sends a new invitation email to all selected guests who are still in **Pending** status. Guests who have already accepted or failed are skipped. This is useful when invitations were missed, caught by spam filters, or simply expired before the guest had a chance to respond.
- **Remove** — Permanently deletes the selected guest accounts from your [Entra ID](/docs/glossary/glossary) tenant. This action is irreversible. Each guest immediately loses access to all resources in your organization, including Teams, SharePoint sites, groups, and applications. If you need to collaborate with a removed guest again in the future, you must send a new invitation. Before performing a bulk removal, consider reviewing each guest's [access information](/docs/guests/guest-details) to understand the full impact.

> **Good to know:** Bulk removal is powerful but permanent. If you are cleaning up guests after a project ends, it is a good practice to run an [access review](/docs/access-reviews/overview) first to document which guests were removed and why, providing a clear audit trail for compliance purposes.

## Related Pages

- [Inviting Guests](/docs/guests/inviting-guests) — How to add new guest users
- [Guest Details](/docs/guests/guest-details) — Detailed view of individual guests
- [Best Practices](/docs/guests/best-practices) — Recommendations for guest lifecycle management
- [Troubleshooting](/docs/guests/troubleshooting) — Common issues and solutions
