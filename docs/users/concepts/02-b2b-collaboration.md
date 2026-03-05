---
title: B2B Collaboration
---

# B2B Collaboration

Azure AD B2B collaboration is the mechanism that makes external partner access possible in Microsoft 365. Understanding how it works helps you make better decisions about who to invite, what to grant, and when to revoke.

## How Guest Accounts Work

When you invite an external user to your organization, Entra ID creates a **guest object** in your directory. This guest does not get a new set of credentials in your tenant. Instead, they authenticate with their **home tenant** (or personal Microsoft account) and then access resources in yours through a trust relationship.

You will notice that guest accounts have a distinctive User Principal Name (UPN) that typically follows the pattern `user_partner.com#EXT#@yourdomain.onmicrosoft.com`. This naming convention makes guests easy to identify in directory listings and audit logs.

The guest object in your directory stores metadata about the external user, including their display name, email, and sign-in activity, but the actual identity and credentials remain under the control of the user's home organization.

> **Good to know:** Because guests authenticate through their home tenant, you are relying on that organization's security policies (MFA, password strength, conditional access) unless you configure your own conditional access policies to enforce additional requirements at sign-in.

## The Invitation Flow

The B2B invitation process follows a predictable sequence:

1. **Invitation sent** — Partner365 sends an invitation through the Microsoft Graph API on your behalf.
2. **Email delivered** — The guest receives an invitation email with a redemption link.
3. **Redemption** — The guest clicks the link and authenticates with their home identity provider.
4. **Consent** — The guest consents to the permissions your organization requests (typically basic profile access).
5. **Account activation** — The guest object in your directory moves from a pending state to active, and the user can now access shared resources.

In some scenarios, redemption happens automatically. If you have configured [cross-tenant access policies](01-cross-tenant-policies.md) with automatic redemption enabled for a partner, the guest skips the email step entirely and can access resources the first time they navigate to them. This is common for close business partners where you want a frictionless experience.

Partner365 tracks invitation status so you can see which guests have redeemed and which are still pending. See the [Guest List](../guests/01-guest-list.md) for details on monitoring invitation states.

## Guest vs Member User Type

Entra ID distinguishes between two user types: **Guest** and **Member**. This distinction affects default permissions more than most administrators expect.

**Guests** have restricted default permissions. They cannot enumerate the full directory, have limited visibility in Teams, and cannot discover other users or groups unless explicitly granted access. These restrictions exist because external users should not have broad visibility into your organization by default.

**Members** have broader default permissions, including the ability to read directory objects, enumerate groups, and discover users.

Partner365 focuses specifically on guest-type users because they represent external access. Every guest is a potential access path into your environment from an outside organization, which is why tracking and reviewing them matters.

> **Good to know:** The Guest vs Member distinction is about default permissions, not authentication. You can change a user's type after creation, but doing so changes what they can see and do in your tenant. Think carefully before promoting a guest to member.

## What Guests Can Access by Default

A newly redeemed guest can access very little on their own. By default, guests can:

- View their own profile information
- See limited properties of other users they interact with directly
- Access resources that have been **explicitly shared** with them

Guests cannot browse your SharePoint sites, Teams channels, or applications unless you grant access. Each resource must be individually shared:

- **Teams** — Guests must be added to specific teams.
- **SharePoint** — Guests must be invited to specific sites or given links.
- **Groups** — Guests must be added as members of Microsoft 365 groups.
- **Applications** — Guests must be assigned to enterprise applications.

This restrictive default posture is intentional and aligns with least-privilege principles. It also means that entitlement management and access packages become important tools for granting access at scale without manual per-resource sharing. See [Access Packages](../entitlements/01-access-packages.md) for how Partner365 helps manage this.

## Lifecycle Considerations

Guest accounts do not expire by default. Once created, a guest retains access to any resources they were granted until someone explicitly removes them or revokes their access. This creates a real security concern: **stale guests**.

A stale guest is someone who has not signed in for an extended period but still has active access to your resources. Common causes include:

- A partner employee who changed roles and no longer needs access
- A contractor whose engagement ended but whose guest account was not cleaned up
- A vendor relationship that concluded without a formal offboarding process

Stale guests are a security risk because they represent dormant access paths. If a stale guest's home account is compromised, an attacker could use that trust relationship to access your resources.

Partner365 helps manage guest lifecycle in several ways:

- The [Guest List](../guests/01-guest-list.md) displays last sign-in dates and flags stale guests.
- [Access Reviews](../access-reviews/01-overview.md) let you periodically verify that each guest still needs access.
- The [Trust Score](03-trust-score.md) for a partner penalizes a high ratio of stale guests, making lifecycle issues visible at a glance.

> **Good to know:** Regularly scheduled access reviews are the most effective way to prevent guest sprawl. Even a quarterly review can catch the majority of stale accounts before they become a risk. See the [Glossary](../glossary/01-glossary.md) for definitions of key terms used throughout this documentation.
