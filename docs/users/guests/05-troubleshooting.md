---
title: Troubleshooting
---

# Troubleshooting

This page covers common issues you may encounter when managing [B2B guest users](/docs/glossary/glossary) in Partner365 and provides guidance on resolving them. Most guest-related problems stem from cross-tenant policy configurations, email delivery issues, or Microsoft Graph API timing behaviors.

## Invitation Failed

When an invitation shows a **Failed** status, the Microsoft Graph API returned an error during the invitation attempt. Check the error details on the [guest detail page](/docs/guests/guest-details) for the specific error message. Common causes include:

- **Invalid email address** — A typo in the email address, a non-existent mailbox, or a domain that does not resolve. Double-check the address and try again with the corrected value.
- **Partner's tenant blocks inbound B2B invitations** — The guest's home organization may have configured their [Entra ID](/docs/glossary/glossary) external collaboration settings to block inbound invitations from external tenants. This is outside your control — the partner's IT team must allow inbound B2B collaboration from your tenant.
- **Your outbound cross-tenant policy blocks the partner** — Your own [cross-tenant access policy](/docs/glossary/glossary) may be configured to block outbound invitations to that partner's tenant. Review the partner's policy configuration in Partner365 to confirm outbound B2B collaboration is allowed.
- **Domain is on the blocked collaboration domains list** — Entra ID allows tenant administrators to maintain an allow-list or block-list of domains for external collaboration. If the guest's email domain is blocked, invitations to that domain will fail. Check your tenant's external collaboration settings in the Entra admin center.

> **Good to know:** If you consistently see failures for guests from the same organization, the problem is almost certainly a policy issue rather than individual email problems. Start by verifying your cross-tenant outbound policy and asking the partner to verify their inbound policy.

## Guest Cannot Access Resources

A guest with **Accepted** status who reports they cannot access resources they should have access to is a common support scenario. Work through these checks in order:

1. **Verify group and application assignments** — Open the guest's [detail page](/docs/guests/guest-details) and review the Groups, Applications, Teams, and SharePoint Sites tabs. If the guest is not a member of the expected resources, the fix is simply to add them to the correct group or application assignment.
2. **Check conditional access policies** — Your tenant's [conditional access policies](/docs/glossary/glossary) may be blocking the guest. Policies that require compliant devices, specific locations, or particular authentication strengths can prevent guest access. Review the CA policies page for any rules that apply to the guest's partner organization.
3. **MFA requirements** — If your conditional access policies require multi-factor authentication and the partner does not have [MFA trust](/docs/glossary/glossary) enabled in your cross-tenant policy, the guest must complete MFA in your tenant separately from their home tenant MFA. This can be confusing for guests who believe they have already completed MFA. Enabling MFA trust in the cross-tenant policy for that partner eliminates this friction.
4. **License requirements** — Some applications and features require specific Microsoft 365 licenses. Guests typically use the host tenant's licenses, but certain scenarios may require explicit license assignment.

## Guest Shows as "Pending" for a Long Time

A guest remaining in **Pending** status means they have not yet clicked the redemption link in their invitation email. This does not necessarily indicate a technical problem — the guest may simply have missed or ignored the email. Steps to resolve:

- **Check spam and junk folders** — Ask the guest to look in their spam, junk, or quarantine folders. Microsoft invitation emails are sometimes flagged by aggressive email filters.
- **Resend the invitation** — Use the Resend Invitation action on the [guest detail page](/docs/guests/guest-details) to send a fresh email with a new redemption link. This is the fastest and easiest step to try.
- **Partner email filtering** — The guest's organization may have email security appliances or policies that block Microsoft invitation emails entirely. If resending does not work, ask the guest or their IT team to check whether emails from `invites@microsoft.com` are being filtered.
- **Domain allow-list restrictions** — If your tenant's external collaboration settings use a domain allow-list (rather than a block-list), confirm the guest's email domain is included. Invitations to domains not on the allow-list will appear to send successfully but may not be deliverable.
- **Direct contact** — If multiple resends fail to get a response, reach out to the guest through another channel (phone, Teams chat via another tenant, or through the partner's contact person) to confirm they are aware of the invitation and able to receive it.

## Guest Removed but Still Has Access

After removing a guest through Partner365, you may observe that the guest can still access some resources for a short period. This is expected behavior:

- **Eventual consistency** — Removal via the Microsoft Graph API is eventually consistent across all Microsoft 365 services. It may take several minutes for all services (Teams, SharePoint, Exchange) to process the deletion and revoke access.
- **Cached OAuth tokens** — The guest may hold cached authentication tokens that remain valid until they expire. OAuth access tokens in Microsoft 365 typically have a lifetime of approximately one hour. During this window, the guest can continue using resources they have already authenticated to, even though their account object has been deleted.

No action is required on your part. Access will be fully revoked once existing tokens expire, which typically resolves within one hour of removal.

## "Stale" Guest Still Active

The stale indicator on the [guest list](/docs/guests/guest-list) and [detail page](/docs/guests/guest-details) is based on the **last sign-in** timestamp reported by the Microsoft Graph API. In some cases, a guest may appear stale even though they are actively using resources:

- **Background resource access** — Certain types of access, such as reading a shared mailbox, background file synchronization, or automated workflows that use the guest's identity, may not update the interactive sign-in timestamp in Entra ID.
- **Sign-in data latency** — Microsoft's sign-in reporting pipeline has some latency. Recent sign-ins may take up to 24 hours to appear in the Graph API responses that Partner365 uses.
- **Sync timing** — Partner365 refreshes guest data during its periodic sync cycle. If the guest signed in after the last sync, the stale flag will persist until the next sync runs. You can trigger a manual sync from **Admin > Sync** to refresh the data immediately.

If the guest is genuinely active, the stale flag will clear after the next successful sync that picks up their updated sign-in timestamp. If you are unsure whether a guest is truly active, check with the team owner or project lead before removing them.

> **Good to know:** The stale indicator is a signal, not a verdict. Use it to identify guests who warrant investigation, but always verify before taking action — especially for guests involved in critical collaborations.

## Related Pages

- [Guest List](/docs/guests/guest-list) — View and manage all guest users
- [Guest Details](/docs/guests/guest-details) — Detailed view of individual guests
- [Inviting Guests](/docs/guests/inviting-guests) — How to add new guest users
- [Best Practices](/docs/guests/best-practices) — Recommendations for guest lifecycle management
