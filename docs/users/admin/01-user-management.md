---
title: User Management
admin: true
---

# User Management

The Admin > Users page is where administrators control who can access Partner365 and what they can do.
Every person who uses the application must have a user account here with an assigned role and an approved status.

## User List

The user list displays a table of all registered Partner365 users.
Each row shows:

- **Name** — The user's display name, sourced from their registration or SSO profile.
- **Email** — The email address associated with their account.
- **Role** — Their current permission level: Admin, Operator, or Viewer.
  Displayed as a badge with an inline dropdown for quick changes.
- **Account Status** — Either Approved (active, can use the app) or Pending (registered but awaiting admin approval).
- **Last Login** — The date and time of their most recent sign-in.
  A blank value indicates the user has never logged in, which may mean they registered but never returned, or their invitation is still pending.

The list is searchable and sortable, making it easy to find specific users or identify accounts that have not been active recently.

## Changing Roles

Click the role dropdown next to any user to change their role.
The three roles provide progressively more capability:

- **Viewer** — Read-only access to all data.
  Suitable for team members who need visibility into external collaboration status but should not make changes.
  This is the recommended default for new users.
- **Operator** — Can manage [partners](/docs/glossary/01-glossary), invite and remove [guests](/docs/glossary/01-glossary), run access reviews, and perform day-to-day management tasks.
  Assign this to team members responsible for ongoing partner and guest lifecycle management.
- **Admin** — Full access including system configuration, user management, template management, and all operator capabilities.
  Reserve this for people who need to configure Partner365 itself.

Role changes take effect immediately.
The user's current session will reflect the new permissions on their next page load — they do not need to sign out and back in.
Follow the principle of least privilege: start users at Viewer and elevate only when their responsibilities require it.

## Approving Users

New users who register directly or sign in via [SSO](/docs/glossary/01-glossary) (when auto-approve is disabled) appear in the user list with a Pending status.
These users see a "Pending Approval" page when they try to access the application — they cannot view or interact with any Partner365 data until approved.

Before approving a user:

- Verify their identity.
  Confirm that the name and email correspond to someone who should have access.
- Determine the appropriate role.
  Assign the minimum role needed for their responsibilities.
- Consider whether they need access at all.
  Not everyone who finds the login page should be approved.

Once you click **Approve**, the user can immediately access Partner365 with their assigned role.

## Removing Users

Click the delete button on a user's row to remove their Partner365 access.
This action:

- Prevents the user from signing in to Partner365 going forward.
- **Does not** affect their Entra ID account, email, Microsoft 365 licenses, or any other Microsoft services.
  Removal is scoped entirely to the Partner365 application.
- Is recorded in the [activity log](/docs/users/activity/01-activity-log) with the admin who performed the removal and the timestamp.

Use removal when someone leaves the team, changes roles within the organization, or no longer needs access to external collaboration management.
If the person needs access again later, they would need to register or sign in via SSO and be re-approved.

> **Good to know:** If you are using SSO with auto-approve disabled, check the Users page regularly for pending approvals.
> New team members will not be able to access Partner365 until an admin reviews and approves their account.
> Consider establishing a process for new hires that includes notifying a Partner365 admin to watch for their pending account.
