---
title: Guest Details
---

# Guest Details

The guest detail page provides a comprehensive view of a single [B2B guest user](/docs/glossary/01-glossary), including their profile information, invitation status, and — critically — the full scope of what they can access in your tenant. This is the page to visit when you need to understand exactly what a guest can do in your organization before making changes to their account.

## Overview

The top section of the guest detail page displays the guest's core profile and status information:

- **Display Name** — The guest's name as it appears across your Microsoft 365 services. This is set during invitation and may be updated by the guest through their home tenant profile.
- **Email** — The guest's external email address at their home organization. This is their primary identifier and the address that received the original invitation.
- **User Principal Name (UPN)** — The technical identifier for the guest in your [Entra ID](/docs/glossary/01-glossary) directory. Guest UPNs follow the `#EXT#` format, which looks like `jane.doe_contoso.com#EXT#@yourtenant.onmicrosoft.com`. The `#EXT#` suffix indicates this is an external identity, not a member of your tenant. The part before `#EXT#` is derived from the guest's email address with the `@` replaced by an underscore. You will encounter this format in audit logs, PowerShell queries, and conditional access policy evaluations.
- **Invitation Status** — The current state of the guest's invitation: **Pending** (awaiting redemption), **Accepted** (active account), or **Failed** (error during invitation). The date of the most recent status change is displayed alongside the status badge, so you can see exactly when the guest accepted or when a failure occurred.
- **Partner Organization** — The [partner organization](/docs/glossary/01-glossary) this guest is associated with. Click the link to navigate to the partner detail page, where you can view cross-tenant policies, other guests from the same organization, and the overall collaboration relationship.
- **Last Sign-In** — The most recent time the guest authenticated to your tenant. If the guest has not signed in recently, a staleness indicator appears to flag the account for review. An accepted guest with no sign-in activity is a potential security concern and should be investigated — they may no longer need access.
- **Created Date** — When the original invitation was sent. Combined with Last Sign-In, this gives you a clear picture of the guest's account age and activity level.

## Access Information Tabs

Below the overview section, five tabs provide detailed information about what the guest can access in your tenant. Each tab loads data on-demand from the Microsoft Graph API when you click it, so there may be a brief delay on the first click as the data is fetched.

### Groups

Displays the Microsoft 365 groups and security groups the guest belongs to in your tenant. Group memberships are the primary mechanism that determines what resources a guest can access — a guest added to a SharePoint-connected group gains access to that group's SharePoint site, Planner plans, shared mailbox, and other associated resources.

Review group memberships carefully. If a guest belongs to many groups, they may have accumulated more access than originally intended, especially if groups were added over time for different projects. Periodic review of group memberships is essential for maintaining the [principle of least privilege](/docs/users/guests/04-best-practices).

### Applications

Lists the enterprise applications the guest has been granted access to in your tenant. This shows which line-of-business apps, SaaS integrations, and custom applications the guest can sign into. Application access is typically granted through group membership or direct assignment. If you see applications listed here that the guest should not have access to, check whether the access comes from a group assignment or a direct one, and remove accordingly.

### Teams

Shows the Microsoft Teams that the guest is a member of. Guests who are members of a Team can participate in channel conversations, access shared files stored in the Team's SharePoint site, join meetings, and view channel tabs. Teams membership is one of the most common reasons guests are invited in the first place, as it enables real-time collaboration across organizational boundaries.

Keep in mind that each Team has an underlying Microsoft 365 group, so Teams memberships also appear in the Groups tab. Removing a guest from a Team also removes them from the associated group and vice versa.

### SharePoint Sites

Displays the SharePoint Online sites the guest has been granted access to, along with their permission level at each site. SharePoint access is particularly important to review because it represents document-level access — a guest with access to a SharePoint site can view, edit, or download files depending on their permission level.

Permission levels typically include Read (view only), Contribute (add and edit items), and Edit (full content management). Understanding what permission level a guest has helps you assess the risk of their continued access.

### Loading Behavior

Each of these tabs queries the Microsoft Graph API when first selected. Depending on the number of resources and current API performance, data may take a moment to populate. Results are cached for the duration of your session on the page, so switching between tabs after the initial load is instant.

> **Good to know:** Before removing a guest, take a few minutes to review all five access tabs. Understanding exactly what the guest has access to helps you assess the impact of removal and ensures you are not inadvertently disrupting an active collaboration. If the guest is a member of shared Teams channels or has access to critical SharePoint sites, coordinate with the relevant team owners before proceeding.

## Actions

The guest detail page provides two actions, available to users with the **Operator** or **Admin** role:

### Resend Invitation

Available only for guests in **Pending** status. This sends a new invitation email with a fresh redemption link to the guest's email address. Use this when the original invitation was missed, filtered as spam, or simply forgotten. There is no limit to the number of times you can resend, but if multiple attempts fail to produce a response, consider contacting the guest directly to confirm they received the email and that their organization is not blocking Microsoft invitation messages.

### Remove Guest

Permanently deletes the guest user object from your Entra ID tenant. This action is irreversible. The guest immediately loses access to all resources in your organization — every group membership, application assignment, Teams membership, and SharePoint permission is revoked. If you need to collaborate with this person again in the future, you must send a brand new invitation, and all previous access assignments will need to be reconfigured.

Because removal is permanent and far-reaching, confirm that the guest's access is no longer needed before proceeding. Check with team owners and project leads if you are unsure whether the collaboration is truly finished.

## Related Pages

- [Guest List](/docs/users/guests/01-guest-list) — View and manage all guest users
- [Inviting Guests](/docs/users/guests/02-inviting-guests) — How to add new guest users
- [Best Practices](/docs/users/guests/04-best-practices) — Recommendations for guest lifecycle management
- [Troubleshooting](/docs/users/guests/05-troubleshooting) — Common issues and solutions
