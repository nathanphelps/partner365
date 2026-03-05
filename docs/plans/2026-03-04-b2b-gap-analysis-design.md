# B2B Gap Analysis — Design Document

**Date:** 2026-03-04
**Status:** Approved

## Context

A gap analysis of Partner365 against the full Microsoft Entra B2B ecosystem identified 13 missing capabilities. Three are selected for implementation now; the rest are tracked as GitHub issues.

## Implementation Scope

### Feature 1: External Collaboration Settings (Admin)

**Problem:** Tenant-wide settings that control who can invite guests and which domains are allowed/blocked are only manageable through the Entra admin center. Admins need to manage these from Partner365.

**Location:** New admin page at `/admin/collaboration`.

**Graph API Endpoints:**
- `GET /policies/authorizationPolicy` — reads guest invite restrictions, guest user role, allowed/blocked domains
- `PATCH /policies/authorizationPolicy` — updates invitation and access settings

**UI — Single-page settings form with two cards:**

**Card 1 — Guest Invitation Controls:**
- Who can invite guests: radio group (None / Admins only / Admins + Guest Inviters / Admins + Members / Everyone including guests)
- Maps to `allowInvitesFrom` on the authorization policy

**Card 2 — Domain Restrictions:**
- Mode toggle: Allow all / Allow list / Block list
- Editable domain list (add/remove domains, text input)
- Maps to `allowedToInvite` / `blockedFromInvite` domain collections

**Backend:**
- New `CollaborationSettingsService` wrapping the authorization policy endpoints
- New `AdminCollaborationController` with `show()` and `update()` actions
- Activity log entries for `SettingsUpdated` when changed

---

### Feature 2: B2B Direct Connect (Partner Detail Page)

**Problem:** We store a single `direct_connect_enabled` boolean per partner, but direct connect has inbound/outbound directions and requires mutual trust. Admins have no visibility into whether direct connect is actually functional.

**Location:** New card on the existing partner Show page (`/partners/{id}`), below B2B collaboration toggles.

**Graph API:** Already using `GET/PATCH /policies/crossTenantAccessPolicy/partners/{tenantId}` — the `b2bDirectConnect` property is in the same response.

**UI — "Direct Connect" card:**
- Inbound toggle — allow their users to connect to our shared channels
- Outbound toggle — allow our users to connect to their shared channels
- Status badge:
  - "Active" (green) — both inbound and outbound enabled
  - "Partial" (yellow) — only one direction enabled
  - "Disabled" (gray) — both off
  - Tooltip explaining mutual trust requirement
- Info callout: "Direct Connect is currently limited to Teams shared channels. Both organizations must enable it for shared channels to work."

**Backend:**
- Extend `CrossTenantPolicyService` with direct connect config methods (or fold into existing `updatePartner()`)
- Migration: replace `direct_connect_enabled` with `direct_connect_inbound_enabled` and `direct_connect_outbound_enabled` on `partner_organizations`
- Update `SyncPartners` to sync both direction flags
- Activity log: reuse `PolicyChanged` action

---

### Feature 3: Tenant Restrictions v2 (Partner Detail Page)

**Problem:** No way to manage what external apps your own users can access in partner tenants. This is a key data exfiltration prevention control.

**Location:** New card on the partner Show page, below the Direct Connect section.

**Graph API:** The `tenantRestrictions` property is already in the partner policy response — we just don't use it yet.
- `GET /policies/crossTenantAccessPolicy/partners/{tenantId}` — read `tenantRestrictions`
- `PATCH /policies/crossTenantAccessPolicy/partners/{tenantId}` — update `tenantRestrictions`

**UI — "Tenant Restrictions" card:**
- Enable/disable toggle
- When enabled:
  - **Applications:** Radio for "Allow all apps" / "Block specific apps" / "Allow only specific apps", with app list editor (display name + app ID pairs)
  - **Scope:** Radio for "Apply to all users" / "Apply to specific users/groups", with user/group selector if scoped
- Info callout: "Tenant Restrictions control what your users can access when signing into this partner's tenant. Requires Global Secure Access or a corporate proxy to enforce."

**Backend:**
- Extend `CrossTenantPolicyService` with `getTenantRestrictions()` and `updateTenantRestrictions()` methods
- Migration: add `tenant_restrictions_enabled` boolean and `tenant_restrictions_json` to `partner_organizations`
- Update `SyncPartners` to sync tenant restrictions state
- Activity log: reuse `PolicyChanged` action

**Note:** We manage the policy configuration only — enforcement mechanism (Global Secure Access / corporate proxy) is out of scope.

---

## Deferred Items (GitHub Issues)

The following gaps are tracked as GitHub issues for future consideration:

1. **Granular Policy Configuration** — Per-user/group/app scoping for inbound/outbound B2B access
2. **Cross-Tenant Synchronization** — Automated B2B user provisioning across tenants
3. **Multitenant Organization (MTO)** — Formal MTO setup for orgs with multiple Entra tenants
4. **Access Reviews for Guests** — Automated periodic reviews to certify continued guest access
5. **Entitlement Management / Access Packages** — Self-service access request workflows with approval chains
6. **Conditional Access Visibility** — Show which CA policies affect external/guest users per partner
7. **Guest Sign-In Activity & Stale Account Detection** — Surface last sign-in, stale guest alerts
8. **Guest Expiration / Lifecycle Policies** — Auto-disable/remove guests after inactivity period
9. **Compliance Reporting** — Dashboard showing policy compliance, untrusted partners, guests without MFA
10. **Default Policy Visibility** — UI to view/manage the default cross-tenant access policy

## References

- [Cross-tenant access settings overview](https://learn.microsoft.com/en-us/entra/external-id/cross-tenant-access-overview)
- [Cross-tenant access settings API](https://learn.microsoft.com/en-us/graph/api/resources/crosstenantaccesspolicy-overview?view=graph-rest-1.0)
- [B2B Direct Connect overview](https://learn.microsoft.com/en-us/entra/external-id/b2b-direct-connect-overview)
- [Tenant Restrictions v2](https://learn.microsoft.com/en-us/entra/external-id/tenant-restrictions-v2)
- [Authorization policy API](https://learn.microsoft.com/en-us/graph/api/resources/authorizationpolicy?view=graph-rest-1.0)
