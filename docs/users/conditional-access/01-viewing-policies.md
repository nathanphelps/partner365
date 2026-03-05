---
title: Viewing Policies
---

# Viewing Conditional Access Policies

The Conditional Access page gives you visibility into [conditional access policies](/docs/glossary/01-glossary) synced from Microsoft Entra ID that affect external and guest users in your tenant. This is a read-only view — Partner365 pulls these policies so you can understand what security controls apply to your partners without switching to the Entra admin center.

## Policy List

The index page displays all conditional access policies that target guest or external user types. Each row in the table includes the following columns:

- **Name** — The display name of the policy as configured in Entra ID. Policy names are set by your IT team and typically describe the policy's purpose (for example, "Require MFA for all external users").
- **State** — The current enforcement status of the policy, shown as a colored badge:
  - **Enabled** — The policy is actively enforcing its controls on every matching sign-in. If a guest meets the policy's conditions, the grant controls are applied.
  - **Disabled** — The policy exists but is not enforcing anything. Disabled policies have no effect on sign-ins.
  - **Report-only** — The policy logs what *would* happen during matching sign-ins but does not actually block or require anything. This mode is useful for testing a policy's impact before turning on enforcement.
- **Grant Controls** — A summary of what the policy enforces when its conditions are met. Common values include:
  - *Require MFA* — The user must complete multi-factor authentication.
  - *Block access* — The sign-in is denied entirely.
  - *Require compliant device* — The device must meet your organization's compliance policies in Intune.
  - *Require hybrid Azure AD joined device* — The device must be joined to both on-premises Active Directory and Azure AD.
- **Affected Partners** — The count of partner organizations whose guest users are matched by this policy. This helps you quickly gauge how broadly a policy applies across your external relationships.

## Uncovered Partners Alert

When one or more partner organizations have guest users that are not covered by any conditional access policy, an alert banner appears at the top of the index page. This is important because uncovered guests can access your tenant's resources without additional security controls like MFA or device compliance checks.

If you see this alert, consider creating conditional access policies in the Microsoft Entra admin center that target external or guest user types. Even a baseline policy that requires MFA for all guests significantly improves your security posture. You can click the alert to see which specific partners are uncovered and prioritize accordingly.

## Policy Details

Clicking on any policy in the list opens its detail page, which shows the full configuration synced from Entra ID:

- **Included/Excluded User Types** — Whether the policy targets all guest and external users, or only guests from specific external tenants. Exclusions are also shown, so you can see if certain groups or users are exempt.
- **Target Applications** — Which cloud applications the policy applies to. This may be "All cloud apps" for broad policies, or a specific list of applications (such as SharePoint Online or Microsoft Teams) for more targeted controls.
- **Conditions** — The circumstances under which the policy activates:
  - *Sign-in risk levels* — Whether risky sign-ins (as detected by Entra ID Protection) trigger the policy.
  - *Device platforms* — Whether the policy targets specific platforms like Windows, iOS, Android, or macOS.
  - *Locations* — Named locations or IP ranges that are included or excluded.
  - *Client apps* — Whether the policy applies to browser access, mobile apps, desktop clients, or legacy authentication protocols.
- **Grant Controls** — The full set of controls enforced when conditions match, including whether multiple controls use AND or OR logic.
- **Session Controls** — Additional restrictions such as sign-in frequency (how often re-authentication is required), persistent browser sessions, and app-enforced restrictions.

## Affected Partners

The detail page also includes an Affected Partners section that lists which partner organizations have guests matched by this policy. This helps you understand the blast radius of any policy change — before modifying a policy in the Entra admin center, you can check here to see exactly which partner relationships would be impacted.

> **Good to know:** Conditional access policies are read-only in Partner365. They are synced from Entra ID to give you visibility into what security controls affect your external users. To create, modify, or delete policies, use the [Microsoft Entra admin center](https://entra.microsoft.com). See [Understanding Policies](./02-understanding-policies) for more on how policies work and interact with cross-tenant trust settings.
