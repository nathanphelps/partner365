---
title: Glossary
---

# Glossary

This glossary defines key terms used throughout the Partner365 documentation. Terms are listed alphabetically for quick reference.

---

## Access Package

A bundle of resources (groups, SharePoint sites) that can be requested by external users through entitlement management. Access packages standardize and simplify granting access to multiple resources at once. They are the primary mechanism for controlled, self-service resource provisioning.

[Learn more](/docs/entitlements/access-packages)

## Access Review

A periodic process to verify that guest users still need access to your tenant. Reviews support compliance requirements and reduce risk from stale accounts. Organizations typically schedule reviews on a quarterly or semi-annual basis.

[Learn more](/docs/access-reviews/overview)

## B2B Collaboration

Microsoft's model for inviting external users as guests into your tenant. Guests get an account in your directory and can access shared resources. This is the most common pattern for cross-organization collaboration in Microsoft 365.

[Learn more](/docs/concepts/b2b-collaboration)

## B2B Direct Connect

A model where external users access shared channels in Microsoft Teams without being added as guests. No guest account is created in your directory. This approach is suited for scenarios where persistent directory presence is unnecessary.

[Learn more](/docs/concepts/b2b-collaboration)

## Conditional Access

Entra ID policies that enforce controls (MFA, device compliance, location restrictions) when users access resources. These policies evaluate every sign-in attempt and can require additional verification or block access entirely. Conditional access is a critical layer in securing both internal and external collaboration.

[Learn more](/docs/conditional-access/viewing-policies)

## Cross-Tenant Access Policy

Settings in Entra ID that control how your organization collaborates with a specific external tenant. Includes inbound/outbound rules for B2B collaboration and direct connect, plus trust settings for MFA and device compliance. Each partner organization in Partner365 has an associated cross-tenant access policy.

[Learn more](/docs/concepts/cross-tenant-policies)

## Device Compliance

A status indicating whether a device meets your organization's security requirements (encryption, up-to-date OS, antivirus enabled). Relevant to trust settings in cross-tenant policies -- you can choose to accept or reject a partner's device compliance claims. Trusting device compliance from a partner reduces friction for their users while maintaining security standards.

## Entitlement Management

Entra ID's system for governing access requests, approvals, and lifecycle for internal and external users. In Partner365, this translates to access packages that bundle resources for guest users. Entitlement management automates the approval workflow and enforces expiration policies.

[Learn more](/docs/entitlements/access-packages)

## Guest User

An external user invited into your Microsoft 365 tenant via B2B collaboration. Guest accounts have a directory object but limited default permissions compared to member users. Partner365 tracks guest user status, activity, and associated partner organization.

[Learn more](/docs/guests/guest-list)

## MFA Trust

A cross-tenant trust setting where your organization accepts multi-factor authentication claims from a partner's tenant. When enabled, partner users don't need to complete MFA again in your tenant if they've already authenticated with MFA in their home tenant. This reduces sign-in friction while preserving strong authentication requirements.

[Learn more](/docs/concepts/cross-tenant-policies)

## Partner Organization

An external Microsoft 365 tenant that your organization collaborates with. Partner365 tracks each partner with cross-tenant access policies, trust scores, and associated guest users. Partners are the central organizing entity in the application.

[Learn more](/docs/partners/viewing-partners)

## Remediation

The action taken after an access review decision -- typically removing denied guests from the tenant via the Microsoft Graph API. Remediation is irreversible; removed guests must be re-invited if access is needed again. Always confirm review decisions carefully before applying remediation.

## Sensitivity Label

A Microsoft Purview label applied to documents, emails, and sites indicating their classification level (e.g., Confidential, Internal, Public). Partner365 syncs sensitivity label data for visibility into how labeled content intersects with external access. Understanding label distribution helps identify potential data exposure risks.

## SharePoint Site Permissions

Access grants for guest users to specific SharePoint Online sites. Partner365 syncs this data to show which external users have access to which sites, supporting visibility and compliance monitoring. Site permission data is refreshed during each sync cycle.

## Trust Score

A 0-100 score calculated by Partner365 reflecting a partner's security posture. Based on policy configuration, guest activity, MFA trust settings, and other factors. Higher scores indicate better security alignment with your organization's requirements.

[Learn more](/docs/concepts/trust-score)
