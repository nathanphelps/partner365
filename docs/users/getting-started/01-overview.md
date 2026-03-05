---
title: Overview
---

# Welcome to Partner365

Partner365 helps you manage Microsoft 365 external partner organizations, [cross-tenant access policies](/docs/glossary/01-glossary), and B2B [guest user](/docs/glossary/01-glossary) lifecycle. Instead of juggling multiple admin centers, Partner365 gives you a single place to control who your organization collaborates with, what they can access, and whether that access is still appropriate. This guide covers everything you need to know to use the application effectively.

## Key Concepts

- **Partner Organizations** — External Microsoft 365 tenants that your organization collaborates with. Each partner has [cross-tenant access policies](/docs/glossary/01-glossary) that control what resources they can access. Managing partners centrally lets you maintain a clear inventory of every external relationship and apply consistent security policies across all of them. See [Partner Management](/docs/partners/01-partner-management) for details.

- **Guest Users** — External users invited into your tenant via [B2B collaboration](/docs/glossary/01-glossary). Guests are associated with partner organizations and can be granted access to specific resources. Tracking guests at the partner level helps you spot orphaned accounts and enforce least-privilege access over time. See [Guest Management](/docs/guests/01-guest-management) for details.

- **Cross-Tenant Access Policies** — Settings that control inbound and outbound access between your tenant and partner organizations, including B2B collaboration and B2B direct connect. Getting these right is critical because overly permissive policies can expose internal resources to external users, while overly restrictive policies block legitimate collaboration. See [Cross-Tenant Policies](/docs/concepts/02-cross-tenant-policies).

- **Templates** — Reusable policy configurations that admins can apply when adding new partner organizations. Templates reduce human error by encoding your organization's security standards into a repeatable blueprint, so every new partner starts with the right policy baseline. See [Templates](/docs/templates/01-templates).

- **Access Reviews** — Periodic reviews of [guest user](/docs/glossary/01-glossary) access to ensure compliance and remove stale permissions. Without regular reviews, guest accounts accumulate over time and create unnecessary risk. Access reviews give you a structured process for validating that every external user still needs their access. See [Access Reviews](/docs/reviews/01-access-reviews).

- **Trust Score** — A computed score reflecting the security posture of each partner based on policy configuration and guest activity. The score surfaces partners that may need tighter controls or a policy review, so you can prioritize your attention where it matters most. See [Trust Score](/docs/concepts/03-trust-score).

- **Sensitivity Labels** — [Microsoft Purview](/docs/glossary/01-glossary) labels used to classify and protect content across your tenant. Partner365 surfaces sensitivity label information so you can see which classification levels are being shared with external partners and ensure that highly confidential content is not inadvertently exposed.

- **SharePoint Site Tracking** — Visibility into which SharePoint sites guest users can access. This is especially important because SharePoint is one of the primary surfaces where external collaboration happens, and understanding site-level exposure helps you identify over-shared resources. See [SharePoint Sites](/docs/sharepoint/01-sharepoint-sites).

## How Partner365 Fits Into Your M365 Security

Managing external collaboration in Microsoft 365 typically requires switching between the Entra admin center (for cross-tenant policies and guest accounts), SharePoint admin center (for site sharing), Teams admin center (for external access settings), and Microsoft Purview (for sensitivity labels and compliance). Partner365 centralizes all of this into a single pane of glass.

By bringing cross-tenant policies, guest lifecycle management, and compliance reporting into one application, Partner365 helps you enforce a [Zero Trust](/docs/glossary/01-glossary) approach to external collaboration: verify explicitly, use least-privilege access, and assume breach. Every partner relationship is tracked, every guest account is monitored, and every policy change is logged.

This centralized approach also makes auditing straightforward. Instead of pulling data from multiple admin portals, you can generate compliance reports and review activity logs in one place. For details on how Partner365 enforces security boundaries, see [Security Model](/docs/concepts/04-security-model).

## Roles

Partner365 has three user roles:

| Role | Permissions |
|------|-------------|
| **Viewer** | Read-only access to all data — partners, guests, reviews, reports |
| **Operator** | Everything viewers can do, plus create/edit partners, invite guests, manage access reviews and entitlements |
| **Admin** | Everything operators can do, plus manage users, templates, sync settings, and Graph API configuration |

Viewers are typically security analysts or auditors who need read-only access for monitoring and compliance checks. Operators are collaboration or identity administrators who handle day-to-day partner and guest management. Admins are IT leads or security architects responsible for configuring Partner365 itself, including policy templates and integration settings.

> **Good to know:** Role assignments are managed by admins in the application settings. If you need elevated access, contact your Partner365 admin.

## Navigation

Use the sidebar on the left to navigate between sections. The sidebar collapses on smaller screens — click the menu icon to expand it. You can also access the documentation at any time by clicking the help icon in the header.

Your main sections are:
- **Dashboard** — Overview of key metrics and action items
- **Partners** — View and manage partner organizations
- **Guests** — View and manage guest users
- **SharePoint Sites** — View SharePoint sites and guest access exposure
- **Access Reviews** — Create and manage periodic access reviews
- **Conditional Access** — View conditional access policies affecting external users
- **Sensitivity Labels** — View Microsoft Purview labels applied to shared content
- **Entitlements** — Manage access packages and assignments
- **Reports** — Generate compliance reports
- **Activity** — View the audit log of all actions
