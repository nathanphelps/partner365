---
title: Partner Details
---

# Partner Details

The partner detail page shows comprehensive information about a specific partner organization.

## Cross-Tenant Access Policies

The policy section shows the current configuration for:
- **Inbound B2B Collaboration** — Controls which users from the partner can be invited as guests
- **Outbound B2B Collaboration** — Controls which of your users can be guests in the partner's tenant
- **Inbound B2B Direct Connect** — Controls partner user access to shared channels
- **Outbound B2B Direct Connect** — Controls your user access to the partner's shared channels
- **Trust Settings** — Whether you trust the partner's MFA and device compliance claims

Each policy section shows whether it's configured to allow all, block all, or target specific users/groups/applications.

## Guest Users

A list of all guest users associated with this partner. You can click through to individual guest details or invite new guests directly from this page.

## Trust Score

The trust score (0-100) is calculated based on:
- Policy restrictiveness (more restrictive = higher score)
- Guest activity (inactive guests lower the score)
- MFA trust configuration
- Device compliance trust settings

## Actions

Depending on your role, you can:
- **Edit** the partner's category and notes (Operator+)
- **Update** cross-tenant access policies (Operator+)
- **Delete** the partner organization (Operator+)
