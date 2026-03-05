---
title: Partner Details
---

# Partner Details

The partner detail page is the central hub for managing a specific external organization. It shows the partner's current policy configuration, guest users, trust score breakdown, and related activity. Everything you need to assess and adjust the relationship lives here.

## Cross-Tenant Access Policies

The policy section displays the six core [cross-tenant access policy](/docs/concepts/01-cross-tenant-policies) toggles that govern how your tenant interacts with this partner. Each toggle can be set to allow all, block all, or target specific users, groups, or applications.

- **Inbound B2B Collaboration** — Controls which users from the partner organization can be invited as [guest users](/docs/glossary/01-glossary) in your tenant. Enable this for partners you actively collaborate with. Disable or restrict it if you want to block guest invitations from this organization entirely.

- **Outbound B2B Collaboration** — Controls which of your users can accept guest invitations to the partner's tenant. Enable this when your staff need to access resources in the partner's environment. Restrict it to specific groups if only certain teams collaborate externally.

- **Inbound B2B Direct Connect** — Allows partner users to access your Teams shared channels without being added as guests. This is useful for real-time collaboration but grants broader access than traditional guest invitations. Enable only for trusted partners with ongoing shared-channel needs.

- **Outbound B2B Direct Connect** — Allows your users to participate in the partner's Teams shared channels. Similar considerations as inbound — enable when your teams need shared-channel access to the partner's environment.

- **MFA Trust** — When enabled, your tenant accepts [multi-factor authentication](/docs/glossary/01-glossary) claims from the partner's tenant. This means partner users who have already completed MFA in their home tenant are not prompted again when accessing your resources. Enable this for partners with mature MFA policies to reduce friction. Disable it if you are unsure about the partner's MFA enforcement.

- **Device Compliance Trust** — When enabled, your tenant trusts the partner's Intune device compliance status. Partner users on compliant devices can satisfy your conditional access policies without re-evaluation. Enable this for partners with managed device fleets (typically subsidiaries or closely integrated vendors).

> **Good to know:** Changes to these toggles are written to Microsoft Entra ID via the Graph API in real time. The partner's trust score recalculates on the next sync cycle.

## Tenant Restrictions

The tenant restrictions section lets you define an allow or block list for applications. Use this when you want to restrict which of your apps the partner's users can access, or limit your users' access to specific partner apps.

When to use tenant restrictions:
- **Vendor partners** — Restrict access to only the specific applications relevant to the engagement.
- **High-sensitivity environments** — Block access to certain internal apps while allowing collaboration tools.

## Guest Users

This section lists all guest users in your tenant who are associated with this partner organization. For each guest, you can see their display name, email, invitation status, and last sign-in date.

From here you can:
- Click any guest to open their full [guest details](/docs/users/guests/02-guest-details) page.
- Click **Invite Guest** to send a new B2B invitation for a user from this partner's domain.

> **Good to know:** If a guest has not signed in for an extended period, consider running an [access review](/docs/users/guests/04-access-reviews) to determine whether their access is still needed.

## Trust Score

The [trust score](/docs/concepts/03-trust-score) section shows the partner's overall score (0-100) along with a detailed breakdown of each contributing factor. The breakdown table displays each factor, its status (pass or fail), and its weight in the overall calculation.

| Factor | What it measures | If failing |
|---|---|---|
| Policy restrictiveness | Whether policies are narrowly scoped rather than allow-all | Tighten inbound/outbound settings to target specific groups or apps |
| MFA trust alignment | Whether MFA trust matches the partner's actual MFA maturity | Disable MFA trust if the partner lacks strong MFA enforcement |
| Device compliance trust | Whether compliance trust is warranted | Disable if the partner does not manage devices via Intune |
| Guest activity | Whether guests from this partner are actively signing in | Review inactive guests and remove those no longer needed |
| Stale guest ratio | Percentage of guests with no recent activity | Run an access review and clean up stale accounts |
| Policy coverage | Whether explicit policies exist vs. relying on defaults | Configure explicit inbound/outbound policies |

## Additional Tabs

Beyond the main policy and trust score views, the partner detail page includes several additional tabs:

- **Conditional Access** — Shows conditional access policies that affect users from this partner's tenant. Useful for understanding the full access control picture beyond cross-tenant policies alone.
- **Sensitivity Labels** — Displays any sensitivity labels applied to content shared with this partner. Helps verify that information protection policies are in place for the collaboration.
- **SharePoint Sites** — Lists SharePoint sites where this partner's guest users have access. Provides visibility into which content repositories are exposed to the external organization.

## Actions

The actions available depend on your [role](/docs/glossary/01-glossary):

- **Edit category and notes** (Operator or Admin) — Update the partner's classification or add internal notes for your team.
- **Update policies** (Operator or Admin) — Modify any of the six cross-tenant access policy toggles described above. Changes are pushed to Entra ID immediately.
- **Delete partner** (Operator or Admin) — Removes the partner from Partner365 and deletes the corresponding `crossTenantAccessPolicy/partners` entry from Microsoft Entra ID. This action is irreversible. All explicit policy configuration for this partner is lost, and the tenant default policy will apply to any future interactions with their users.

> **Good to know:** Before deleting a partner, review their guest user list. Deleting the partner does not automatically remove guest accounts — run an access review or manually remove guests if the collaboration has ended. See [Best Practices](/docs/users/partners/04-best-practices) for recommended cleanup steps.
