---
title: Understanding Policies
---

# Understanding Conditional Access Policies

[Conditional access policies](/docs/glossary/glossary) are the primary mechanism in Microsoft Entra ID for enforcing security requirements at sign-in time. This page explains how these policies affect your guest users, the patterns most commonly used for external access, and how they interact with other Partner365 features like cross-tenant trust.

## How Conditional Access Affects Guests

Every time a guest user signs in to your tenant, Entra ID evaluates all active conditional access policies to determine whether additional security controls are required. For guest users specifically, policies can:

- **Require additional authentication** — The guest must complete multi-factor authentication (MFA) before accessing resources, even if they already authenticated in their home tenant.
- **Restrict device types** — Only devices that meet your compliance standards or are hybrid Azure AD joined are allowed.
- **Limit access by location** — Sign-ins are restricted to approved geographic regions or IP address ranges, blocking access from untrusted networks.
- **Block access entirely** — Certain conditions (such as high-risk sign-ins or legacy protocols) result in a complete denial of access.

Guests may face different or stricter requirements than your internal users. This is by design — external users represent a higher risk surface because your organization does not manage their identities or devices directly. Conditional access lets you apply proportionate controls to mitigate that risk.

## Common Policy Patterns

IT teams typically implement one or more of the following patterns for managing external user access:

### Require MFA for all external users

This is the most common and most recommended pattern. Every guest must verify their identity with a second factor (such as a phone notification, authenticator app, or SMS code) before accessing any resource. This single policy dramatically reduces the risk of compromised guest accounts being used to access your data.

### Block access from untrusted locations

Geographic fencing restricts sign-ins to approved countries or known corporate IP ranges. If a guest attempts to sign in from an unexpected location, the sign-in is blocked. This is particularly useful for organizations with regulatory requirements around data residency or access from sanctioned regions.

### Require compliant devices

This pattern ensures that guests can only access resources from devices that meet your organization's security standards as defined in Microsoft Intune. This is the strictest device-based control and is typically used for partners who need access to sensitive data. Note that this requires the guest's device to be enrolled in your Intune instance, which adds friction to the onboarding process.

### Block legacy authentication

Older authentication protocols like IMAP, POP3, and basic SMTP authentication do not support modern security features such as MFA. Blocking these protocols forces all sign-ins through modern authentication flows where conditional access can be fully enforced. This is considered a security baseline and is recommended for all tenants.

### Report-only mode

Before enforcing any new policy, you can deploy it in report-only mode first. In this mode, Entra ID evaluates the policy against every matching sign-in and records the result in sign-in logs — but does not actually block access or require MFA. This lets you see exactly how many users would be affected and identify any unintended consequences before switching to enforcement.

## Policy Evaluation Order

Unlike firewall rules, conditional access policies do not have an explicit priority or ordering. Instead, all policies that match a given sign-in are evaluated simultaneously, and the **most restrictive controls win**. For example:

- If Policy A requires MFA and Policy B requires a compliant device, the user must satisfy both requirements.
- If Policy A requires MFA and Policy B blocks access, the block takes precedence — the user is denied access regardless of whether they could complete MFA.

This "most restrictive wins" behavior means you should be careful about overlapping policies. A broadly scoped block policy can override more permissive policies you intended for specific partners or applications.

> **Good to know:** Because all matching policies are combined, there is no way to create an "allow" policy that overrides a block. The only way to exempt users from a blocking policy is to add them to that policy's exclusion list.

## Interaction with Cross-Tenant Trust

Conditional access and [cross-tenant access policies](/docs/concepts/cross-tenant-policies) work together to determine the guest experience. The most significant interaction involves MFA trust:

- **Without MFA trust** — When a guest signs in, your tenant's conditional access policy requires MFA. The guest must complete MFA *in your tenant*, even if they already completed MFA in their home tenant. This means the guest may be prompted for MFA twice — once at home and once in your tenant.
- **With MFA trust enabled** — If you configure the partner's cross-tenant access policy to trust their MFA claims, the guest's home-tenant MFA satisfies your conditional access requirement. The guest experiences seamless access without a second MFA prompt.

This distinction has a real impact on user experience. Trusted partners get frictionless access while maintaining your security requirements. Untrusted partners face additional prompts that can cause confusion and support tickets. When deciding whether to enable MFA trust for a partner, consider the partner's security posture and whether their MFA implementation meets your standards.

For details on configuring cross-tenant trust, see [Cross-Tenant Policies](/docs/concepts/cross-tenant-policies).

## Why Policies Are Read-Only in Partner365

Partner365 syncs conditional access data from Entra ID to give you visibility, but it intentionally does not allow modifications. There are several reasons for this:

- **Broad impact** — Conditional access policies affect all users in your tenant, not just guests. A misconfigured policy could lock out internal users or disrupt critical business processes.
- **Testing workflow** — Best practice is to test policies in report-only mode and review sign-in logs before enforcement. This workflow is best handled in the Entra admin center where you have access to the full set of diagnostic tools.
- **Audit and compliance** — Changes to conditional access policies are sensitive security operations that should go through your organization's change management process.

To create or modify policies, use the [Microsoft Entra admin center](https://entra.microsoft.com). Start with report-only mode, review the impact in sign-in logs, and then switch to enforcement when you are confident in the results.

> **Good to know:** The "Uncovered Partners" alert on the [Viewing Policies](./01-viewing-policies) page is a great starting point for improving your conditional access coverage. It identifies partner organizations whose guests are not matched by any policy, helping you prioritize where to focus your efforts.
