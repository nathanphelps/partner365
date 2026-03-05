---
title: Collaboration Settings
admin: true
---

# Collaboration Settings

The Admin > Collaboration page displays your tenant's external collaboration settings as configured in Microsoft Entra ID.
These are tenant-wide defaults that apply to all [partner](/docs/glossary/glossary) organizations and [guest](/docs/glossary/glossary) accounts as a baseline.
Partner-specific [cross-tenant access policies](/docs/glossary/glossary) can override some of these defaults for individual partners, but the settings shown here represent the global floor.

## What These Settings Control

External collaboration settings govern who can be invited into your tenant, what guests can do once they arrive, and how domain-level restrictions are enforced.
Understanding these settings is important because they interact with the per-partner policies you configure in Partner365 — a restrictive global setting can limit what is possible even when a partner-specific policy is permissive.

## Settings Breakdown

### Guest Invite Restrictions

Controls who within your organization is authorized to send guest invitations:

- **Everyone** — All users, including existing guests themselves, can invite new guests.
  This is the most permissive setting and is generally not recommended for organizations that want to maintain control over external access.
- **Admins and users in specific roles** — Only users with directory roles like Guest Inviter, User Administrator, or Global Administrator can invite guests.
  This provides a balance between flexibility and control.
- **Admins only** — Only Global Administrators and User Administrators can invite guests.
  This is the most restrictive organizational setting.
- **Nobody** — Guest invitations are disabled entirely at the tenant level.
  Partner365's invitation features will not work if this is set.

This setting gates the invitation pipeline.
If Partner365 operators cannot invite guests, check this setting first.

### Collaboration Restrictions

Domain-level controls that determine which external organizations your users can collaborate with through B2B.
See the Domain Restriction Modes section below for details on configuration options.

### External User Leave Settings

Determines whether guest users can remove themselves from your tenant directory without administrator intervention.
When enabled, guests can self-service their departure.
When disabled, only an administrator can remove a guest account.

### Guest User Access Restrictions

Sets the baseline permission level for all guest accounts in your tenant:

- **Limited access** — Guests can see their own profile and limited directory information.
  This is the default and recommended setting.
- **Same as member users** — Guests have the same directory access as regular members.
  This is rarely appropriate and significantly increases the risk surface.
- **Restricted access** — Guests have minimal directory visibility.
  More restrictive than the default, suitable for high-security environments.

## Domain Restriction Modes

The collaboration restrictions setting operates in one of three modes:

- **None (allow all domains)** — No domain restrictions are in place.
  Users from any external domain can be invited as guests.
  Simple but offers no domain-level gatekeeping.
- **Allow list** — Only users from explicitly listed domains can be invited.
  All other domains are blocked.
  Use this when you collaborate with a known, finite set of external organizations and want to prevent invitations to anyone outside that set.
- **Block list** — Users from any domain can be invited except those on the block list.
  Use this to prevent collaboration with specific domains (for example, known competitors or organizations with poor security posture) while allowing all others.

The interface provides an add/remove control with domain badges for managing the list.
When switching between modes, existing invitations are not retroactively affected — the mode governs future invitations only.

> **Good to know:** If you use an allow list for collaboration restrictions, remember to add new partner domains to the list before trying to invite guests from those organizations.
> Otherwise, the invitation will fail at the Entra ID level regardless of what Partner365 does.

## Read-Only Notice

These settings are displayed in Partner365 for visibility but are **read-only**.
Changes must be made in the Microsoft Entra admin center (under External Identities > External collaboration settings).

Partner365 shows these settings so that administrators can see the full picture in one place — global collaboration settings alongside partner-specific cross-tenant policies.
This makes it easier to understand why a particular invitation might be blocked or why guests have certain access levels, without needing to switch between Partner365 and the Entra admin center.

The settings are refreshed during each [partner sync](/docs/admin/sync-configuration) cycle to stay current.
