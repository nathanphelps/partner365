---
title: Inviting Guests
---

# Inviting Guests

Inviting a [B2B guest user](/docs/glossary/01-glossary) creates an external identity in your [Entra ID](/docs/glossary/01-glossary) tenant and sends the person an email with a redemption link. Once the guest accepts, they can access resources you have explicitly shared with them — nothing more.

## Prerequisites

Before you can invite a guest, the following conditions must be met:

- **Partner organization exists** — A [partner organization](/docs/glossary/01-glossary) must already be configured in Partner365 for the guest's company. If the partner does not exist yet, create it first from the [Partners page](/docs/partners/01-partner-list). The guest will be associated with this partner for tracking, policy enforcement, and reporting purposes.
- **Graph API connection** — The Microsoft Graph API must be connected and authenticated. If the connection is down, invitations cannot be sent. Check the Admin page if you encounter connectivity errors.
- **Sufficient permissions** — Your account must have the **Operator** or **Admin** role. Viewers can see the guest list but cannot send invitations. See the [glossary](/docs/glossary/01-glossary) for role definitions.

## Steps

1. **Navigate to Guests and click "Invite Guest"** — This opens the invitation form. You can also initiate an invitation from a partner's detail page, which pre-selects the partner organization for you.

2. **Select the Partner Organization** — Choose the partner this guest belongs to from the dropdown. The guest will be linked to this partner for the duration of their account lifecycle. This association determines which [cross-tenant access policies](/docs/glossary/01-glossary) apply to the guest and ensures the guest appears in partner-scoped reports and access reviews.

3. **Enter the Email Address** — This must be the guest's external work email address at their home organization (for example, `jane.doe@contoso.com`). Do not use an internal address from your own tenant or a consumer email like Gmail or Outlook.com, as these will either fail or create a guest account that cannot be governed by cross-tenant policies.

4. **Enter the Display Name** — This is how the guest will appear in your directory, in Teams, in SharePoint, and in other Microsoft 365 services. Use the guest's real name for clarity. The guest can update their own display name later from their home tenant profile.

5. **Add a Personal Message (optional)** — This text is included in the invitation email the guest receives. Use it to provide context about why they are being invited and what they will have access to. A brief note like "You've been invited to collaborate on the Project Alpha SharePoint site" helps the guest understand the purpose and encourages them to accept promptly.

6. **Click "Send Invitation"** — Partner365 sends the invitation through the Microsoft Graph API. If successful, the guest appears in your list with **Pending** status. If an error occurs, you will see a failure message with details.

## What the Guest Experiences

After you send the invitation, the guest receives an email from Microsoft on behalf of your organization. The email contains a redemption link and your optional personal message. When the guest clicks the link, they are prompted to sign in with their home tenant credentials — they do not create a new password in your tenant. During the redemption process, the guest consents to your organization's access request, which grants your tenant a limited profile reference in their identity. After redemption completes, the guest can access any resources you have explicitly shared with them, such as Teams channels, SharePoint sites, or applications.

> **Good to know:** Guests authenticate with their own organization's credentials. They do not get a password in your tenant. If their home account is disabled or deleted, they automatically lose access to your resources too. This is a key security advantage of the B2B model — you do not have to manage their credentials.

## Invitation Lifecycle

Every guest invitation progresses through a defined set of states:

- **Pending** — The invitation has been sent but the guest has not yet clicked the redemption link. This is the initial state for all new invitations. Invitations do not expire by default, but a guest who remains pending for an extended period may need a reminder.
- **Accepted** — The guest successfully redeemed the invitation and their account is active in your tenant. They can now access resources based on the groups, applications, and sites you have assigned to them.
- **Failed** — An error occurred during the invitation process. Common causes include invalid email addresses, cross-tenant policy blocks, and collaboration domain restrictions. Check the error details on the [guest detail page](/docs/users/guests/03-guest-details) for specifics. See also the [troubleshooting guide](/docs/users/guests/05-troubleshooting).

Invitations can be resent from the guest detail page or via [bulk actions](/docs/users/guests/01-guest-list) on the guest list. Resending generates a new redemption link and sends a fresh email to the guest.

## Related Pages

- [Guest List](/docs/users/guests/01-guest-list) — View and manage all guest users
- [Guest Details](/docs/users/guests/03-guest-details) — Detailed view of individual guests
- [Troubleshooting](/docs/users/guests/05-troubleshooting) — Common invitation issues and solutions
