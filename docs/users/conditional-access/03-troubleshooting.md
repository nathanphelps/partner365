---
title: Troubleshooting
---

# Troubleshooting Conditional Access

This page covers common issues related to [conditional access policies](/docs/glossary/glossary) and guest users, along with steps to diagnose and resolve them using information available in Partner365.

## Guest Blocked by Conditional Access

When a guest user reports being unable to access resources, conditional access is one of the most common causes. To investigate:

1. **Identify which policies affect the guest's partner.** Open the partner's detail page and check the Conditional Access tab, which lists all policies that match guests from that organization. Alternatively, open a specific policy's detail page to see its affected partners list.
2. **Check for common block causes:**
   - **MFA not completed** — The guest's home tenant may not have MFA enabled, or MFA trust is not configured in the partner's cross-tenant access policy. Without trust, the guest must complete MFA in your tenant and may not have registered a second factor.
   - **Device not compliant** — The guest is using a personal or unmanaged device that does not meet your Intune compliance requirements. This is common when policies require compliant or hybrid Azure AD joined devices.
   - **Sign-in from a blocked location** — The guest is attempting to sign in from a geographic region or IP range that your policies exclude.
   - **Legacy authentication protocol** — The guest's email client or application is using a legacy protocol (IMAP, POP3, basic SMTP) that is blocked by policy.
3. **Take corrective action.** If the partner should be trusted for MFA, enable MFA trust in the partner's cross-tenant access policy settings within Partner365. For device compliance issues, consider whether the policy is appropriate for external users or whether an exception is needed. For location blocks, verify the guest's expected sign-in location against your named locations configuration.

> **Good to know:** The Entra ID sign-in logs (available in the Entra admin center) provide the definitive record of why a sign-in was blocked, including which specific policy and control caused the failure. Partner365 helps you understand which policies apply, but the sign-in logs give you the per-event detail.

## Understanding Report-Only Mode

Policies in report-only mode evaluate every matching sign-in and log the result, but do not enforce any controls. This means:

- Guests are never blocked or prompted for MFA by a report-only policy.
- The sign-in logs in the Entra admin center show what *would* have happened — whether the sign-in would have been blocked, would have required MFA, or would have passed.
- Report-only results appear separately from enforced policy results in the logs, so you can distinguish between actual enforcement and simulated outcomes.

Use report-only mode to test new policies before enabling them. Review the sign-in logs over several days to understand the impact, identify any users who would be blocked unexpectedly, and adjust the policy's scope or conditions before switching to enforcement. This is especially important for policies targeting external users, where unexpected blocks can disrupt partner relationships.

## "Uncovered Partners" Alert

The alert on the [Viewing Policies](./01-viewing-policies) page indicates that some partner organizations have guest users who are not matched by any conditional access policy. Before taking action, consider the context:

- **This is not necessarily a problem.** You may intentionally choose not to apply additional controls to certain low-risk partners, especially if they have a small number of guests with limited resource access.
- **For security best practices**, consider creating a baseline conditional access policy that applies to all guest and external user types. A policy requiring MFA for all external users is the most common starting point and provides meaningful security improvement with minimal friction.
- **Review the uncovered partners list** and assess each case individually. Partners with access to sensitive resources should almost certainly be covered by at least one conditional access policy. Partners with limited access to low-sensitivity resources may be acceptable exceptions.

To resolve the alert, create appropriate policies in the Microsoft Entra admin center targeting external user types. After the next sync cycle, the alert will update to reflect the new coverage.

## Policy Data Out of Date

Conditional access policies sync on the regular background schedule, which runs every 15 minutes by default. If you have just created or modified policies in the Microsoft Entra admin center, there will be a short delay before the changes appear in Partner365.

To see changes immediately, navigate to **Admin > Sync** and trigger a manual sync. The sync pulls the latest policy configurations from Entra ID and updates all affected partner coverage counts. Once the sync completes, return to the Conditional Access page to verify your changes are reflected.

> **Good to know:** If policies still appear out of date after a manual sync, check the sync status on the Admin page for any errors. The most common cause is an expired or insufficient Graph API credential — conditional access policy sync requires the `Policy.Read.All` permission on the application registration.
