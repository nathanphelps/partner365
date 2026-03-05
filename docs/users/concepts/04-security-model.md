---
title: Security Model
---

# Security Model

Partner365 is designed to manage sensitive cross-tenant configurations and external user access. Its security model reflects that responsibility, with layered controls that limit who can do what and a comprehensive audit trail for accountability.

## Role-Based Access Control

Partner365 implements three roles, following the principle of least privilege:

- **Viewer** — Read-only access to all data. Viewers can browse partners, guests, policies, and activity logs but cannot create, modify, or delete anything. This role is ideal for auditors, compliance staff, and stakeholders who need visibility without the ability to make changes.
- **Operator** — Full day-to-day management capabilities. Operators can invite and remove guests, create and modify partners and their cross-tenant policies, run access reviews, and manage entitlements. Most regular users of Partner365 should have this role.
- **Admin** — Everything Operators can do, plus system configuration. Admins manage templates, SSO settings, user accounts, and role assignments. This role should be limited to a small number of trusted administrators.

Each role inherits the permissions of the roles below it. An Admin can do everything an Operator can, and an Operator can do everything a Viewer can.

> **Good to know:** When in doubt, assign the Viewer role first and upgrade later. It is much easier to grant additional access than to clean up after an accidental modification. See the [Overview](../getting-started/01-overview.md) for the full role permissions table.

## What Partner365 Can and Can't Modify

Understanding the boundary between what Partner365 writes and what it only reads is important for your security review.

**Write operations** — Partner365 can create, update, and delete:

- Partner organizations and their cross-tenant access policies (inbound and outbound)
- Guest user invitations and removals
- Access review definitions and responses
- Entitlement management access packages and assignments

These write operations go through the Microsoft Graph API using the application credentials configured for your tenant.

**Read-only data** — Partner365 syncs the following for visibility but does not modify them:

- Conditional access policies
- External collaboration settings
- Sensitivity labels
- SharePoint site data and guest permissions
- Directory user and group information

If you need to change any read-only data, you must do so in the Entra admin center or through other administrative tools. Partner365 surfaces this information so you can make informed decisions without switching between multiple consoles.

> **Good to know:** The read-only boundary is enforced at the API permission level. Partner365's application registration should only be granted write permissions for the resources it needs to manage. This limits the blast radius if credentials are ever compromised.

## Audit Trail

Every write action performed through Partner365 is recorded in the activity log. Each log entry captures:

- **Who** — The user who performed the action
- **What** — The specific operation (create, update, delete) and the affected resource
- **When** — A timestamp of the action

The activity log is append-only and cannot be modified or deleted through the application. This provides a reliable compliance record for internal audits, security reviews, and incident investigations.

Operators and Viewers can read the activity log. Only the log entries relevant to Partner365's own operations are recorded; changes made directly in the Entra admin center are not captured here (those appear in the Entra ID audit logs instead).

See the [Activity Log](../activity/01-activity-log.md) documentation for details on filtering and exporting log data.

## Authentication

Partner365 supports two authentication methods:

- **Local credentials** — Username and password authentication powered by Laravel Fortify. Suitable for development environments and organizations that do not use Entra ID for internal applications.
- **Entra ID SSO** — Single sign-on through your organization's Entra ID tenant. SSO users authenticate with their existing corporate credentials, eliminating the need for separate passwords. Administrators can configure whether new SSO users are auto-approved or require manual admin approval before gaining access.

When SSO is configured, it becomes the primary authentication method. Local credentials can remain available as a fallback or be restricted to specific admin accounts.

> **Good to know:** For production deployments, Entra ID SSO is strongly recommended. It centralizes authentication, ensures your organization's conditional access policies apply, and reduces the risk of weak or reused passwords. See [SSO Settings](../admin/06-sso-settings.md) for configuration instructions.

## Zero Trust Alignment

Partner365's design aligns with Zero Trust security principles, which is particularly relevant when managing external access:

- **Verify explicitly** — MFA trust settings let you require multi-factor authentication for partner users accessing your resources. Cross-tenant policies ensure that access is authenticated and authorized at every step rather than assumed from a network boundary.
- **Use least privilege** — RBAC limits what each Partner365 user can do. Targeted cross-tenant policies scope external access to specific users, groups, and applications rather than granting blanket access. Access packages provide time-limited, scoped entitlements.
- **Assume breach** — Access reviews ensure that guest access is periodically revalidated. The stale guest indicator flags dormant accounts that could be exploited. The audit trail provides forensic capability when investigating security incidents. The [Trust Score](03-trust-score.md) surfaces configuration weaknesses before they become incidents.

Together, these capabilities help you maintain a strong security posture for your external collaboration environment. For definitions of security terms used throughout this documentation, see the [Glossary](../glossary/01-glossary.md).
